// =====================================================================
// Konfigurasi firmware ESP32 + DHT22 (+ OLED) — monitoring ruang server
// Nilai rahasia (WiFi, alamat server, token) ada di secrets.h, BUKAN di sini.
// =====================================================================
#pragma once

// ---------- Sensor DHT22 (modul 3 pin: VCC→3V3, DATA→GPIO4, GND→GND) ----------
#define DHT_PIN            4
#define SEND_INTERVAL_MS   30000UL   // interval kirim (spesifikasi sistem: 30 dtk; heartbeat server 90 dtk)
#define READ_SAMPLES       3         // jumlah pembacaan per interval, diambil median
#define READ_GAP_MS        2500UL    // jeda antarpembacaan (DHT22 minimal 2 dtk)

// Rentang valid DHT22 (sama dengan validasi server): suhu -40..80 °C, kelembaban 0..100 %RH
#define TEMP_MIN  -40.0f
#define TEMP_MAX   80.0f
#define HUM_MIN     0.0f
#define HUM_MAX   100.0f

// ---------- Jaringan ----------
#define HTTP_CONNECT_TIMEOUT_MS  3000
#define HTTP_TIMEOUT_MS          5000
#define BUFFER_CAPACITY          20        // ±10 menit data saat jaringan/server putus (20 × 30 dtk)
#define MAX_SEND_PER_CYCLE       10        // batas kirim ulang per siklus agar loop tetap tepat waktu
#define WIFI_RESTART_AFTER_MS    300000UL  // restart ESP32 bila WiFi gagal > 5 menit
#define NTP_SERVER_1  "id.pool.ntp.org"
#define NTP_SERVER_2  "pool.ntp.org"
#define DEVICE_HOSTNAME "esp32-01"

// ---------- OLED 0.96" I2C 4 pin (VCC→3V3, GND→GND, SDA→GPIO21, SCL→GPIO22) ----------
#define USE_OLED        1          // 0 = tanpa OLED (firmware tetap jalan normal)
#define OLED_DRIVER_SH1106 0       // 0 = SSD1306/SSD1315 (umum untuk 0.96"), 1 = SH1106 (bila tampilan bergeser/berantakan)
#define OLED_I2C_ADDR   0x3C       // umumnya 0x3C; sebagian modul 0x3D
#define OLED_CONTRAST   40         // 0..255; rendah = umur panel lebih panjang
#define PAGE_DURATION_MS 5000UL    // lama tiap halaman (SUHU, KELEMBABAN, STATUS)
#define TZ_OFFSET_SEC   (7 * 3600) // jam di OLED dalam WIB; data ke server tetap UTC
