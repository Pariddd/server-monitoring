// Tampilan OLED (U8g2). Lihat display.h.
#include "config.h"
#include "display.h"

#if USE_OLED

#include <Arduino.h>
#include <U8g2lib.h>
#include <Wire.h>
#include <time.h>
#include "shared_state.h"

// Pin I2C bawaan ESP32 Dev Module: SDA = GPIO21, SCL = GPIO22.
#if OLED_DRIVER_SH1106
static U8G2_SH1106_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, U8X8_PIN_NONE);
#else
static U8G2_SSD1306_128X64_NONAME_F_HW_I2C u8g2(U8G2_R0, U8X8_PIN_NONE);
#endif

// Font: label & status = Terminus bold 8x16; angka besar = Helvetica Bold 24 px.
#define FONT_LABEL  u8g2_font_t0_16b_tr
#define FONT_SMALL  u8g2_font_t0_12b_tr
#define FONT_BIG    u8g2_font_helvB24_tn
#define FONT_UNIT   u8g2_font_helvB12_tf

enum Page { PAGE_TEMP, PAGE_HUM, PAGE_STATUS, PAGE_SENSOR_ERROR };

static int ox = 0, oy = 0;  // geser isi 0..2 px tiap siklus (pencegah burn-in)

// ---------------------------------------------------------------- bagian atas
// Pita atas 16 px (pada OLED dua warna = area kuning): label halaman + ikon status.
static void drawTopBar(const char *label, const SharedState &s) {
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

  // Jumlah data antre (belum terkirim), atau '!' bila token ditolak.
  u8g2.setFont(FONT_SMALL);
  if (s.authError)        u8g2.drawStr(92, 12, "!");
  else if (s.queueLen > 0) {
    char q[4];
    snprintf(q, sizeof q, "%u", s.queueLen);
    u8g2.drawStr(96 - (int)u8g2.getStrWidth(q), 12, q);
  }

  u8g2.drawHLine(0, 15, 128);
}

// ---------------------------------------------------------------- angka besar
static void drawValue(int x, float value, bool valid, const char *unit) {
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

// ---------------------------------------------------------------- ikon
// Termometer; level 0..1 = tinggi air raksa.
static void drawThermometer(int x, int y, float level) {
  x += ox; y += oy;
  u8g2.drawRFrame(x + 3, y, 9, 33, 4);
  u8g2.drawCircle(x + 7, y + 36, 7);
  u8g2.setDrawColor(0);
  u8g2.drawBox(x + 4, y + 28, 7, 4);  // buka sambungan tabung–bulatan
  u8g2.setDrawColor(1);
  u8g2.drawDisc(x + 7, y + 36, 4);
  int top = y + 30 - (int)(level * 26.0f);
  u8g2.drawBox(x + 5, top, 5, (y + 34) - top);
  for (int i = 0; i < 4; i++) u8g2.drawHLine(x + 14, y + 5 + i * 6, 3);
}

// Tetes air; phase 0..1: tetes kecil jatuh (0..0.7) lalu riak (0.7..1).
static void drawDrop(int x, int y, float phase) {
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

// ---------------------------------------------------------------- halaman
static float easeOut(float t) { t = constrain(t, 0.0f, 1.0f); return 1.0f - (1.0f - t) * (1.0f - t); }

static void drawTempPage(const SharedState &s, uint32_t elapsed) {
  drawTopBar("SUHU", s);
  float target = s.hasReading ? constrain((s.temperature - 15.0f) / 20.0f, 0.05f, 1.0f) : 0.05f;  // skala 15–35 °C
  drawThermometer(2, 19, target * easeOut(elapsed / 800.0f));
  drawValue(26, s.temperature, s.hasReading, "\xC2\xB0" "C");  // "°C" (UTF-8)
}

static void drawHumPage(const SharedState &s, uint32_t elapsed) {
  drawTopBar("KELEMBABAN", s);
  drawDrop(2, 17, (elapsed % 1200) / 1200.0f);
  drawValue(28, s.humidity, s.hasReading, "%");
}

static void drawStatusPage(const SharedState &s) {
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

static void drawSensorErrorPage(const SharedState &s) {
  drawTopBar("SENSOR", s);
  u8g2.setFont(FONT_LABEL);
  u8g2.drawStr(4 + ox, 38 + oy, "DHT22 ERROR");
  u8g2.drawStr(4 + ox, 56 + oy, "cek kabel");
}

// ---------------------------------------------------------------- task
static void displayTask(void *) {
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
      switch (page) {
        case PAGE_TEMP:         page = PAGE_HUM; break;
        case PAGE_HUM:          page = PAGE_STATUS; break;
        case PAGE_SENSOR_ERROR: page = PAGE_STATUS; break;
        case PAGE_STATUS:
          page = s.sensorError ? PAGE_SENSOR_ERROR : PAGE_TEMP;
          cycle++;
          ox = cycle % 3;        // geser 0,1,2 px
          oy = (cycle / 3) % 2;  // geser 0,1 px
          break;
      }
      pageStart = now;
      elapsed = 0;
    }

    u8g2.clearBuffer();
    switch (page) {
      case PAGE_TEMP:         drawTempPage(s, elapsed); break;
      case PAGE_HUM:          drawHumPage(s, elapsed); break;
      case PAGE_STATUS:       drawStatusPage(s); break;
      case PAGE_SENSOR_ERROR: drawSensorErrorPage(s); break;
    }
    u8g2.sendBuffer();

    // ±20 fps saat animasi berjalan, selebihnya hemat CPU.
    bool animating = (page == PAGE_HUM) || (page == PAGE_TEMP && elapsed < 900);
    vTaskDelay(pdMS_TO_TICKS(animating ? 50 : 250));
  }
}

void displayStart() {
  // Cek apakah OLED benar-benar terpasang (u8g2.begin() tidak melaporkan kegagalan).
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
  // Core 0 (core 1 dipakai loop utama). Stack 4 KB cukup untuk U8g2 full-buffer.
  xTaskCreatePinnedToCore(displayTask, "oled", 4096, nullptr, 1, nullptr, 0);
  Serial.println(F("[OLED] aktif"));
}

#else  // USE_OLED == 0

void displayStart() {}

#endif
