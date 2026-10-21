// =====================================================================
// ESP32 + DHT22 + OLED 0.96" — sensor suhu & kelembaban ruang server
// Skripsi: Rancang Bangun Sistem Monitoring Resource Server dan Kondisi
// Lingkungan Ruang Server Berbasis Laravel, Telegraf, dan ESP32 dengan Notifikasi
//
// Alur tiap 30 dtk:
//   1. baca DHT22 3x (jeda 2,5 dtk), ambil median
//   2. beri stempel waktu UTC dari NTP (tidak mengirim bila jam belum sinkron)
//   3. masukkan ke antrean RAM (maks 20 data, untuk saat WiFi/server putus)
//   4. kirim antrean dari yang tertua: POST /api/ingest/esp32 {temperature, humidity, ts}
//      dengan header Authorization: Bearer <token>
// Status alarm (FIRING/RESOLVED) dan notifikasi Telegram ditentukan SERVER, bukan ESP32.
//
// Board  : ESP32 Dev Module (core "esp32 by Espressif Systems" 3.3.x)
// Library: DHT sensor library (Adafruit), Adafruit Unified Sensor, U8g2 (olikraus)
//
// Rangkaian:
//   DHT22 modul 3 pin : VCC -> 3V3, DATA -> GPIO4,  GND -> GND
//   OLED I2C 4 pin    : VCC -> 3V3, GND  -> GND,    SDA -> GPIO21, SCL -> GPIO22
// =====================================================================

#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <Wire.h>
#include <U8g2lib.h>
#include <time.h>

// =====================================================================
// 1. RAHASIA — WAJIB DIISI
//    PERINGATAN: berkas yang berisi password WiFi dan token JANGAN di-commit/di-push.
//    Saran: salin berkas .ino ini ke folder sketch Arduino di luar repo (mis. Documents\Arduino),
//    isi rahasia di salinan itu. Versi di repo tetap berisi contoh.
//    (Opsional: bila ada berkas secrets.h di folder yang sama, nilai di bawah diabaikan.)
// =====================================================================
#if __has_include("secrets.h")
#include "secrets.h"
#else
#define WIFI_SSID      "nama-wifi"
#define WIFI_PASSWORD  "password-wifi"
// IP WiFi laptop server (cek: ipconfig). Server dijalankan: php artisan serve --host=0.0.0.0 --port=8000
#define SERVER_URL     "http://192.168.1.10:8000/api/ingest/esp32"
// Token dari: php artisan source:create esp32-01 esp32   (tampil sekali, diawali pm_)
#define SOURCE_TOKEN   "pm_ganti_dengan_token_asli"
#endif

// =====================================================================
// 2. PENGATURAN
// =====================================================================
// Sensor
#define DHT_PIN            4
#define SEND_INTERVAL_MS   30000UL   // interval kirim (spesifikasi: 30 dtk; heartbeat server 90 dtk)
#define READ_SAMPLES       3         // pembacaan per interval, diambil median
#define READ_GAP_MS        2500UL    // jeda antarpembacaan (DHT22 minimal 2 dtk)
#define TEMP_MIN  -40.0f             // rentang valid DHT22 (sama dengan validasi server)
#define TEMP_MAX   80.0f
#define HUM_MIN     0.0f
#define HUM_MAX   100.0f

// Jaringan
#define HTTP_CONNECT_TIMEOUT_MS  3000
#define HTTP_TIMEOUT_MS          5000
#define BUFFER_CAPACITY          20        // ±10 menit data saat putus (20 x 30 dtk)
#define MAX_SEND_PER_CYCLE       10        // batas kirim ulang per siklus
#define WIFI_RESTART_AFTER_MS    300000UL  // restart bila WiFi gagal > 5 menit
#define NTP_SERVER_1  "id.pool.ntp.org"
#define NTP_SERVER_2  "pool.ntp.org"
#define DEVICE_HOSTNAME "esp32-01"

// OLED
#define USE_OLED           1          // 0 = tanpa OLED (firmware tetap jalan normal)
#define OLED_DRIVER_SH1106 0          // 0 = SSD1306/SSD1315 (umum 0.96"), 1 = SH1106 (bila gambar bergeser)
#define OLED_I2C_ADDR      0x3C       // umumnya 0x3C; sebagian modul 0x3D
#define OLED_CONTRAST      40         // 0..255; rendah = umur panel lebih panjang
#define PAGE_DURATION_MS   5000UL     // lama tiap halaman: SUHU, KELEMBABAN, STATUS
#define TZ_OFFSET_SEC      (7 * 3600) // jam di OLED dalam WIB; data ke server tetap UTC

// =====================================================================
// 3. TIPE DATA & VARIABEL GLOBAL
//    (semua tipe didefinisikan sebelum fungsi pertama agar prototipe otomatis Arduino IDE benar)
// =====================================================================

// Status bersama antara loop utama (core 1) dan task OLED (core 0).
struct SharedState {
  bool    hasReading  = false;  // sudah pernah ada pembacaan valid
  float   temperature = NAN;    // °C, 1 desimal
  float   humidity    = NAN;    // %RH, 1 desimal
  bool    sensorError = false;  // pembacaan terakhir gagal seluruhnya
  bool    wifiOk      = false;
  bool    serverOk    = false;  // pengiriman terakhir diterima server (HTTP 200)
  bool    authError   = false;  // server menolak token (401/403)
  bool    ntpOk       = false;  // jam sudah tersinkron NTP
  uint8_t queueLen    = 0;      // jumlah pembacaan yang belum terkirim
};

// Satu pembacaan yang menunggu dikirim.
struct Reading {
  float  temperature;
  float  humidity;
  time_t ts;  // detik epoch UTC
};

enum SendResult { SEND_OK, SEND_DROP, SEND_RETRY_LATER, SEND_AUTH_ERROR };
enum Page { PAGE_TEMP, PAGE_HUM, PAGE_STATUS, PAGE_SENSOR_ERROR };

SharedState g_state;
portMUX_TYPE g_stateMux = portMUX_INITIALIZER_UNLOCKED;
#define STATE_LOCK()   portENTER_CRITICAL(&g_stateMux)
#define STATE_UNLOCK() portEXIT_CRITICAL(&g_stateMux)

DHT dht(DHT_PIN, DHT22);

// Antrean (ring buffer)
Reading  queueBuf[BUFFER_CAPACITY];
uint8_t  qHead = 0, qCount = 0;
uint32_t droppedReadings = 0;

// WiFi & siklus
uint32_t wifiLostSince = 0;
uint32_t lastReconnectTry = 0;
uint32_t lastCycle = 0;
bool     firstCycle = true;

#if USE_OLED
#if OLED_DRIVER_SH1106
U8G2_SH1106_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, U8X8_PIN_NONE);
#else
U8G2_SSD1306_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, U8X8_PIN_NONE);
#endif
#define FONT_LABEL u8g2_font_t0_16b_tr    // label & status: Terminus bold 8x16
#define FONT_SMALL u8g2_font_t0_12b_tr
#define FONT_BIG   u8g2_font_helvB24_tn   // angka besar: Helvetica Bold 24 px
#define FONT_UNIT  u8g2_font_helvB12_tf
int ox = 0, oy = 0;  // geser isi 0..2 px tiap siklus (pencegah burn-in)
#endif

// =====================================================================
// 4. STATUS BERSAMA
// =====================================================================
SharedState stateSnapshot() {
  STATE_LOCK();
  SharedState copy = g_state;
  STATE_UNLOCK();
  return copy;
}

// =====================================================================
// 5. ANTREAN
// =====================================================================
void queuePush(const Reading &r) {
  if (qCount == BUFFER_CAPACITY) {  // penuh: buang yang tertua
    qHead = (qHead + 1) % BUFFER_CAPACITY;
    qCount--;
    droppedReadings++;
  }
  queueBuf[(qHead + qCount) % BUFFER_CAPACITY] = r;
  qCount++;
}

void queuePop() {
  qHead = (qHead + 1) % BUFFER_CAPACITY;
  qCount--;
}

void publishQueueLen() {
  STATE_LOCK();
  g_state.queueLen = qCount;
  STATE_UNLOCK();
}

// =====================================================================
// 6. SENSOR DHT22
// =====================================================================
float median(float *v, int n) {
  // n = jumlah nilai valid (1..3)
  for (int i = 0; i < n; i++)
    for (int j = i + 1; j < n; j++)
      if (v[j] < v[i]) { float t = v[i]; v[i] = v[j]; v[j] = t; }
  if (n == 2) return (v[0] + v[1]) / 2.0f;
  return v[n / 2];
}

// Baca beberapa kali dan ambil median. false bila semua pembacaan gagal.
bool readSensor(float &temperature, float &humidity) {
  float t[READ_SAMPLES], h[READ_SAMPLES];
  int nt = 0, nh = 0;

  for (int i = 0; i < READ_SAMPLES; i++) {
    if (i > 0) delay(READ_GAP_MS);
    float tv = dht.readTemperature(false, true);  // force: baca ulang dari sensor
    float hv = dht.readHumidity(false);
    if (!isnan(tv) && tv >= TEMP_MIN && tv <= TEMP_MAX) t[nt++] = tv;
    if (!isnan(hv) && hv >= HUM_MIN && hv <= HUM_MAX) h[nh++] = hv;
  }

  if (nt == 0 || nh == 0) return false;
  temperature = roundf(median(t, nt) * 10.0f) / 10.0f;  // resolusi DHT22 0,1
  humidity = roundf(median(h, nh) * 10.0f) / 10.0f;
  return true;
}

// =====================================================================
// 7. WAKTU & WIFI
// =====================================================================
bool timeSynced() { return time(nullptr) > 1700000000; }  // > Nov 2023 = sudah sinkron NTP

void ensureWifi() {
  bool ok = WiFi.status() == WL_CONNECTED;
  STATE_LOCK();
  g_state.wifiOk = ok;
  if (!ok) g_state.serverOk = false;
  STATE_UNLOCK();

  if (ok) {
    wifiLostSince = 0;
    return;
  }

  uint32_t now = millis();
  if (wifiLostSince == 0) wifiLostSince = now;

  if (now - lastReconnectTry > 30000UL) {
    Serial.println(F("[WiFi] terputus, mencoba menyambung ulang"));
    WiFi.reconnect();
    lastReconnectTry = now;
  }

  if (now - wifiLostSince > WIFI_RESTART_AFTER_MS) {
    Serial.println(F("[WiFi] gagal > 5 menit, restart ESP32"));
    delay(200);
    ESP.restart();
  }
}

// =====================================================================
// 8. PENGIRIMAN KE SERVER
// =====================================================================
SendResult sendReading(const Reading &r) {
  HTTPClient http;
  http.setConnectTimeout(HTTP_CONNECT_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);

  if (!http.begin(SERVER_URL)) {
    Serial.println(F("[HTTP] SERVER_URL tidak valid"));
    return SEND_RETRY_LATER;
  }
  http.addHeader("Content-Type", "application/json");
  http.addHeader("Accept", "application/json");
  http.addHeader("Authorization", String("Bearer ") + SOURCE_TOKEN);

  char body[96];
  snprintf(body, sizeof body, "{\"temperature\":%.1f,\"humidity\":%.1f,\"ts\":%lld}",
           r.temperature, r.humidity, (long long)r.ts);

  int code = http.POST((uint8_t *)body, strlen(body));
  String resp = code > 0 ? http.getString() : String();
  http.end();

  Serial.printf("[HTTP] %d  %s  %s\n", code, body, resp.c_str());

  if (code == 200) return SEND_OK;
  if (code == 401 || code == 403) return SEND_AUTH_ERROR;  // token salah / bukan token ESP32
  if (code == 422) return SEND_DROP;                         // ditolak permanen (mis. ts di luar rentang)
  return SEND_RETRY_LATER;                                   // 429, 5xx, timeout, server mati
}

// Kirim antrean dari yang tertua; berhenti pada kegagalan agar urutan waktu terjaga.
void flushQueue() {
  if (WiFi.status() != WL_CONNECTED) return;

  for (int sent = 0; qCount > 0 && sent < MAX_SEND_PER_CYCLE; sent++) {
    SendResult res = sendReading(queueBuf[qHead]);

    if (res == SEND_OK || res == SEND_DROP) {
      queuePop();
      publishQueueLen();
      STATE_LOCK();
      g_state.serverOk = (res == SEND_OK);
      g_state.authError = false;
      STATE_UNLOCK();
      continue;
    }

    STATE_LOCK();
    g_state.serverOk = false;
    g_state.authError = (res == SEND_AUTH_ERROR);
    STATE_UNLOCK();
    break;  // coba lagi pada siklus berikutnya
  }
}

// =====================================================================
// 9. TAMPILAN OLED (task FreeRTOS terpisah di core 0)
//    Halaman: SUHU -> KELEMBABAN -> STATUS, masing-masing 5 dtk.
// =====================================================================
#if USE_OLED

// Pita atas 16 px (pada OLED dua warna = area kuning): label + ikon status.
void drawTopBar(const char *label, const SharedState &s) {
  u8g2.setFont(FONT_LABEL);
  u8g2.drawStr(ox, 12 + oy, label);

  // Ikon WiFi (tiga busur) atau tanda silang bila putus.
  const int cx = 120, cy = 12;
  if (s.wifiOk) {
    for (int r = 3; r <= 9; r += 3) u8g2.drawCircle(cx, cy, r, U8G2_DRAW_UPPER_LEFT | U8G2_DRAW_UPPER_RIGHT);
    u8g2.drawPixel(cx, cy);
  } else {
    u8g2.drawLine(cx - 5, 3, cx + 5, 13);
    u8g2.drawLine(cx + 5, 3, cx - 5, 13);
  }

  // Titik server: penuh = data terakhir diterima server, kosong = gagal.
  if (s.serverOk) u8g2.drawDisc(103, 8, 3);
  else            u8g2.drawCircle(103, 8, 3);

  // Jumlah data antre, atau '!' bila token ditolak.
  u8g2.setFont(FONT_SMALL);
  if (s.authError) {
    u8g2.drawStr(92, 12, "!");
  } else if (s.queueLen > 0) {
    char q[4];
    snprintf(q, sizeof q, "%u", s.queueLen);
    u8g2.drawStr(96 - (int)u8g2.getStrWidth(q), 12, q);
  }

  u8g2.drawHLine(0, 15, 128);
}

// Angka besar + satuan.
void drawValue(int x, float value, bool valid, const char *unit) {
  char buf[8];
  if (!valid)              strcpy(buf, "--.-");
  else if (value >= 99.95) snprintf(buf, sizeof buf, "%.0f", value);
  else                     snprintf(buf, sizeof buf, "%.1f", value);

  u8g2.setFont(FONT_BIG);
  u8g2.drawStr(x + ox, 46 + oy, buf);
  int w = u8g2.getStrWidth(buf);
  u8g2.setFont(FONT_UNIT);
  u8g2.drawUTF8(x + ox + w + 2, 32 + oy, unit);
}

// Ikon termometer; level 0..1 = tinggi air raksa.
void drawThermometer(int x, int y, float level) {
  x += ox; y += oy;
  u8g2.drawRFrame(x + 3, y, 9, 33, 4);
  u8g2.drawCircle(x + 7, y + 36, 7);
  u8g2.setDrawColor(0);
  u8g2.drawBox(x + 4, y + 28, 7, 4);  // buka sambungan tabung-bulatan
  u8g2.setDrawColor(1);
  u8g2.drawDisc(x + 7, y + 36, 4);
  int top = y + 30 - (int)(level * 26.0f);
  u8g2.drawBox(x + 5, top, 5, (y + 34) - top);
  for (int i = 0; i < 4; i++) u8g2.drawHLine(x + 14, y + 5 + i * 6, 3);
}

// Ikon tetes air; phase 0..1: tetes kecil jatuh (0..0.7) lalu riak (0.7..1).
void drawDrop(int x, int y, float phase) {
  x += ox; y += oy;
  const int cx = x + 10, cy = y + 21;
  u8g2.drawCircle(cx, cy, 9);
  u8g2.setDrawColor(0);
  u8g2.drawBox(cx - 8, cy - 9, 17, 6);  // hapus busur atas lingkaran
  u8g2.setDrawColor(1);
  u8g2.drawLine(cx, y, cx - 8, cy - 4);
  u8g2.drawLine(cx, y, cx + 8, cy - 4);
  u8g2.drawVLine(cx - 4, cy - 2, 6);    // kilau
  if (phase < 0.7f) {
    int yy = y + 33 + (int)(phase / 0.7f * 9.0f);
    u8g2.drawDisc(cx, yy, 2);
  } else {
    int rr = 2 + (int)((phase - 0.7f) / 0.3f * 7.0f);
    u8g2.drawEllipse(cx, y + 45, rr, 1);
  }
}

float easeOut(float t) {
  t = constrain(t, 0.0f, 1.0f);
  return 1.0f - (1.0f - t) * (1.0f - t);
}

void drawTempPage(const SharedState &s, uint32_t elapsed) {
  drawTopBar("SUHU", s);
  // skala termometer 15-35 °C; terisi bertahap 0,8 dtk saat halaman muncul
  float target = s.hasReading ? constrain((s.temperature - 15.0f) / 20.0f, 0.05f, 1.0f) : 0.05f;
  drawThermometer(2, 19, target * easeOut(elapsed / 800.0f));
  drawValue(26, s.temperature, s.hasReading, "\xC2\xB0" "C");  // "°C" (UTF-8)
}

void drawHumPage(const SharedState &s, uint32_t elapsed) {
  drawTopBar("KELEMBABAN", s);
  drawDrop(2, 17, (elapsed % 1200) / 1200.0f);
  drawValue(28, s.humidity, s.hasReading, "%");
}

void drawStatusPage(const SharedState &s) {
  drawTopBar("STATUS", s);
  u8g2.setFont(FONT_LABEL);
  char line[20];

  snprintf(line, sizeof line, "WiFi   %s", s.wifiOk ? "OK" : "PUTUS");
  u8g2.drawStr(ox, 30 + oy, line);

  const char *srv = s.authError ? "TOKEN?" : (s.serverOk ? "OK" : (s.wifiOk ? "GAGAL" : "--"));
  snprintf(line, sizeof line, "Server %s", srv);
  u8g2.drawStr(ox, 46 + oy, line);

  if (s.queueLen > 0) {
    snprintf(line, sizeof line, "Antre  %u", s.queueLen);
  } else if (s.ntpOk) {
    time_t now = time(nullptr) + TZ_OFFSET_SEC;
    struct tm t;
    gmtime_r(&now, &t);
    snprintf(line, sizeof line, "%02d:%02d WIB", t.tm_hour, t.tm_min);
  } else {
    strcpy(line, "Jam belum");  // NTP belum tersinkron
  }
  u8g2.drawStr(ox, 62 + oy, line);
}

void drawSensorErrorPage(const SharedState &s) {
  drawTopBar("SENSOR", s);
  u8g2.setFont(FONT_LABEL);
  u8g2.drawStr(4 + ox, 38 + oy, "DHT22 ERROR");
  u8g2.drawStr(4 + ox, 56 + oy, "cek kabel");
}

void displayTask(void *param) {
  Page page = PAGE_TEMP;
  uint32_t pageStart = millis();
  uint8_t cycle = 0;

  for (;;) {
    SharedState s = stateSnapshot();
    uint32_t now = millis();
    uint32_t elapsed = now - pageStart;

    // Sensor gagal: halaman SUHU/KELEMBABAN diganti halaman error.
    if (s.sensorError && (page == PAGE_TEMP || page == PAGE_HUM)) page = PAGE_SENSOR_ERROR;
    if (!s.sensorError && page == PAGE_SENSOR_ERROR) page = PAGE_TEMP;

    if (elapsed >= PAGE_DURATION_MS) {
      if (page == PAGE_TEMP) {
        page = PAGE_HUM;
      } else if (page == PAGE_HUM || page == PAGE_SENSOR_ERROR) {
        page = PAGE_STATUS;
      } else {  // PAGE_STATUS: satu siklus selesai
        page = s.sensorError ? PAGE_SENSOR_ERROR : PAGE_TEMP;
        cycle++;
        ox = cycle % 3;        // geser 0,1,2 px
        oy = (cycle / 3) % 2;  // geser 0,1 px
      }
      pageStart = now;
      elapsed = 0;
    }

    u8g2.clearBuffer();
    if (page == PAGE_TEMP)        drawTempPage(s, elapsed);
    else if (page == PAGE_HUM)    drawHumPage(s, elapsed);
    else if (page == PAGE_STATUS) drawStatusPage(s);
    else                          drawSensorErrorPage(s);
    u8g2.sendBuffer();

    // ±20 fps saat animasi, selebihnya hemat CPU.
    bool animating = (page == PAGE_HUM) || (page == PAGE_TEMP && elapsed < 900);
    vTaskDelay(pdMS_TO_TICKS(animating ? 50 : 250));
  }
}

void displayStart() {
  // Cek OLED terpasang (u8g2.begin() tidak melaporkan kegagalan).
  Wire.begin();
  Wire.beginTransmission(OLED_I2C_ADDR);
  if (Wire.endTransmission() != 0) {
    Serial.printf("[OLED] tidak ditemukan di alamat 0x%02X; lanjut tanpa tampilan\n", OLED_I2C_ADDR);
    return;
  }
  u8g2.setI2CAddress(OLED_I2C_ADDR * 2);  // U8g2 memakai alamat 8-bit
  u8g2.begin();
  u8g2.setContrast(OLED_CONTRAST);
  u8g2.enableUTF8Print();
  // Core 0 (loop utama di core 1). Stack 4 KB cukup untuk U8g2 full-buffer.
  xTaskCreatePinnedToCore(displayTask, "oled", 4096, nullptr, 1, nullptr, 0);
  Serial.println(F("[OLED] aktif"));
}

#else
void displayStart() {}
#endif

// =====================================================================
// 10. SETUP & LOOP
// =====================================================================
void setup() {
  Serial.begin(115200);
  delay(200);
  Serial.println(F("\n[BOOT] ESP32 DHT22 monitoring ruang server"));

  dht.begin();
  displayStart();

  WiFi.mode(WIFI_STA);
  WiFi.setHostname(DEVICE_HOSTNAME);
  WiFi.setAutoReconnect(true);
  WiFi.begin(WIFI_SSID, WIFI_PASSWORD);

  configTime(0, 0, NTP_SERVER_1, NTP_SERVER_2);  // UTC; WIB hanya untuk tampilan OLED
}

void loop() {
  ensureWifi();

  bool synced = timeSynced();
  STATE_LOCK();
  g_state.ntpOk = synced;
  STATE_UNLOCK();

  uint32_t now = millis();
  if (!firstCycle && now - lastCycle < SEND_INTERVAL_MS) {
    delay(200);
    return;
  }
  firstCycle = false;
  lastCycle = now;

  float t, h;
  bool ok = readSensor(t, h);
  STATE_LOCK();
  if (ok) {
    g_state.temperature = t;
    g_state.humidity = h;
    g_state.hasReading = true;
    g_state.sensorError = false;
  } else {
    g_state.sensorError = true;
  }
  STATE_UNLOCK();

  if (!ok) {
    Serial.println(F("[DHT22] pembacaan gagal (cek kabel/daya)"));
  } else if (synced) {
    queuePush({t, h, time(nullptr)});
    publishQueueLen();
  } else {
    Serial.println(F("[NTP] jam belum sinkron, pembacaan tidak dikirim"));
  }

  flushQueue();

  if (droppedReadings > 0) {
    Serial.printf("[ANTREAN] %lu pembacaan terlama dibuang karena antrean penuh\n", (unsigned long)droppedReadings);
  }
}
