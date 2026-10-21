// =====================================================================
// SALIN berkas ini menjadi secrets.h lalu isi nilainya.
// secrets.h TIDAK boleh di-commit (sudah ada di .gitignore).
// =====================================================================
#pragma once

#define WIFI_SSID      "nama-wifi"
#define WIFI_PASSWORD  "password-wifi"

// Alamat Laravel di laptop server (IP WiFi laptop, port artisan serve).
// Jalankan server dengan: php artisan serve --host=0.0.0.0 --port=8000
#define SERVER_URL     "http://192.168.1.10:8000/api/ingest/esp32"

// Token dari: php artisan source:create esp32-01 esp32   (tampil sekali, diawali pm_)
#define SOURCE_TOKEN   "pm_ganti_dengan_token_asli"
