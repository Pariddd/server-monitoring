// Logika utama firmware: setup() dan loop() ada di sini (bukan di .ino) agar tidak
// bergantung pada pembuatan prototipe fungsi otomatis Arduino IDE untuk berkas .ino.
#include <Arduino.h>
#include <WiFi.h>
#include <HTTPClient.h>
#include <DHT.h>
#include <time.h>

#include "config.h"
#include "secrets.h"   // salin dari secrets.example.h
#include "shared_state.h"
#include "display.h"

SharedState g_state;
portMUX_TYPE g_stateMux = portMUX_INITIALIZER_UNLOCKED;

static DHT dht(DHT_PIN, DHT22);

// ------------------------------------------------------------- antrean (ring buffer)
struct Reading {
  float temperature;
  float humidity;
  time_t ts;  // detik epoch UTC
};

static Reading queueBuf[BUFFER_CAPACITY];
static uint8_t qHead = 0, qCount = 0;
static uint32_t droppedReadings = 0;

static void queuePush(const Reading &r) {
  if (qCount == BUFFER_CAPACITY) {  // penuh: buang yang tertua
    qHead = (qHead + 1) % BUFFER_CAPACITY;
    qCount--;
    droppedReadings++;
  }
  queueBuf[(qHead + qCount) % BUFFER_CAPACITY] = r;
  qCount++;
}

static const Reading &queueFront() { return queueBuf[qHead]; }

static void queuePop() {
  qHead = (qHead + 1) % BUFFER_CAPACITY;
  qCount--;
}

static void publishQueueLen() {
  uint8_t n = qCount;
  stateUpdate([n](SharedState &s) { s.queueLen = n; });
}

// ------------------------------------------------------------- sensor
static float median3(float *v, int n) {
  // n = jumlah nilai valid (1..3); sortir kecil
  for (int i = 0; i < n; i++)
    for (int j = i + 1; j < n; j++)
      if (v[j] < v[i]) { float t = v[i]; v[i] = v[j]; v[j] = t; }
  if (n == 2) return (v[0] + v[1]) / 2.0f;
  return v[n / 2];
}

// Baca DHT22 beberapa kali dan ambil median. false bila semua pembacaan gagal.
static bool readSensor(float &temperature, float &humidity) {
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
  temperature = roundf(median3(t, nt) * 10.0f) / 10.0f;  // resolusi DHT22 0,1
  humidity = roundf(median3(h, nh) * 10.0f) / 10.0f;
  return true;
}

// ------------------------------------------------------------- waktu & WiFi
static bool timeSynced() { return time(nullptr) > 1700000000; }  // > Nov 2023 = sudah sinkron NTP

static uint32_t wifiLostSince = 0;
static uint32_t lastReconnectTry = 0;

static void ensureWifi() {
  bool ok = WiFi.status() == WL_CONNECTED;
  stateUpdate([ok](SharedState &s) { s.wifiOk = ok; if (!ok) s.serverOk = false; });

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

// ------------------------------------------------------------- pengiriman
enum SendResult { SEND_OK, SEND_DROP, SEND_RETRY_LATER, SEND_AUTH_ERROR };

static SendResult sendReading(const Reading &r) {
  HTTPClient http;
  http.setConnectTimeout(HTTP_CONNECT_TIMEOUT_MS);
  http.setTimeout(HTTP_TIMEOUT_MS);

  if (!http.begin(SERVER_URL)) {
    Serial.println(F("[HTTP] URL server tidak valid (cek secrets.h)"));
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

// Kirim antrean dari yang tertua. Berhenti saat gagal agar urutan waktu terjaga.
static void flushQueue() {
  if (WiFi.status() != WL_CONNECTED) return;

  for (int sent = 0; qCount > 0 && sent < MAX_SEND_PER_CYCLE; sent++) {
    SendResult res = sendReading(queueFront());

    if (res == SEND_OK || res == SEND_DROP) {
      queuePop();
      publishQueueLen();
      bool ok = (res == SEND_OK);
      stateUpdate([ok](SharedState &s) { s.serverOk = ok; s.authError = false; });
      continue;
    }

    bool auth = (res == SEND_AUTH_ERROR);
    stateUpdate([auth](SharedState &s) { s.serverOk = false; s.authError = auth; });
    break;  // coba lagi pada siklus berikutnya
  }
}

// ------------------------------------------------------------- setup & loop
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

static uint32_t lastCycle = 0;
static bool firstCycle = true;

void loop() {
  ensureWifi();

  bool synced = timeSynced();
  stateUpdate([synced](SharedState &s) { s.ntpOk = synced; });

  uint32_t now = millis();
  if (!firstCycle && now - lastCycle < SEND_INTERVAL_MS) {
    delay(200);
    return;
  }
  firstCycle = false;
  lastCycle = now;

  float t, h;
  bool ok = readSensor(t, h);
  if (ok) {
    stateUpdate([t, h](SharedState &s) {
      s.temperature = t;
      s.humidity = h;
      s.hasReading = true;
      s.sensorError = false;
    });
  } else {
    Serial.println(F("[DHT22] pembacaan gagal (cek kabel/daya)"));
    stateUpdate([](SharedState &s) { s.sensorError = true; });
  }

  if (ok && synced) {
    queuePush({t, h, time(nullptr)});
    publishQueueLen();
  } else if (ok) {
    Serial.println(F("[NTP] jam belum sinkron, pembacaan tidak dikirim"));
  }

  flushQueue();

  if (droppedReadings > 0) {
    Serial.printf("[ANTREAN] %lu pembacaan terlama dibuang karena antrean penuh\n", (unsigned long)droppedReadings);
  }
}
