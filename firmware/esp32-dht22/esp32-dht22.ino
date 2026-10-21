// =====================================================================
// ESP32 + DHT22 (+ OLED 0.96") — sensor suhu & kelembaban ruang server
// Skripsi: Rancang Bangun Sistem Monitoring Resource Server dan Kondisi
// Lingkungan Ruang Server Berbasis Laravel, Telegraf, dan ESP32 dengan Notifikasi
//
// Alur tiap 30 dtk:
//   1. baca DHT22 3× (jeda 2,5 dtk), ambil median
//   2. beri stempel waktu UTC dari NTP (tidak mengirim bila jam belum sinkron)
//   3. masukkan ke antrean RAM (maks 20 data)
//   4. kirim antrean dari yang tertua: POST /api/ingest/esp32 {temperature, humidity, ts}
//      + header Authorization: Bearer <token>
// Status alarm (FIRING/RESOLVED) ditentukan server, bukan oleh ESP32.
//
// Board: "ESP32 Dev Module" (core esp32 by Espressif Systems 3.3.x)
// Library: "DHT sensor library" (Adafruit), "Adafruit Unified Sensor", "U8g2" (olikraus)
// =====================================================================

// Seluruh kode ada di app.cpp (setup/loop), display.cpp (OLED), config.h (pengaturan),
// secrets.h (WiFi, alamat server, token — salin dari secrets.example.h).
