# Firmware ESP32 + DHT22 + OLED — sensor ruang server

Mengirim suhu dan kelembaban ruang server ke Laravel (`POST /api/ingest/esp32`) setiap 30 detik,
dan menampilkannya di OLED 0.96". Status alarm (FIRING/RESOLVED) dan notifikasi Telegram
ditentukan oleh server, bukan oleh ESP32.

## 1. Rangkaian

| Komponen | Pin komponen | ESP32 Dev Module |
|---|---|---|
| DHT22 (modul 3 pin) | VCC / + | 3V3 |
| | DATA / OUT | GPIO4 |
| | GND / − | GND |
| OLED 0.96" I2C (4 pin) | VCC | 3V3 |
| | GND | GND |
| | SDA | GPIO21 |
| | SCL | GPIO22 |

Modul DHT22 3 pin sudah memiliki resistor pull-up. Pakai 3V3 (bukan 5V/VIN) untuk kedua modul.

## 2. Arduino IDE

1. Board: **Tools → Board → esp32 → ESP32 Dev Module** (core "esp32 by Espressif Systems" 3.3.x).
2. Library (Sketch → Include Library → Manage Libraries), pasang:
   - **DHT sensor library** (Adafruit)
   - **Adafruit Unified Sensor** (Adafruit) — dibutuhkan DHT sensor library
   - **U8g2** (olikraus)
3. Versi yang dipakai saat uji kompilasi: core esp32 3.3.12, DHT sensor library 1.4.7,
   Adafruit Unified Sensor 1.1.15, U8g2 2.37.1 → hasil: program 1.068.364 byte (81%), RAM global 51.912 byte (15%).

Seluruh kode ada dalam **satu berkas**: `esp32-dht22.ino` (rahasia, pengaturan, sensor, jaringan, OLED).

## 3. Persiapan server (laptop H1)

```bash
# 1) Daftarkan sumber ESP32 (token tampil SEKALI — simpan, jangan ditempel di chat)
php artisan source:create esp32-01 esp32

# 2) Jalankan server agar bisa diakses dari WiFi (dashboard tetap hanya dari laptop ini)
php artisan serve --host=0.0.0.0 --port=8000
```

Cari IP WiFi laptop (PowerShell): `ipconfig` → bagian *Wireless LAN adapter Wi-Fi* → **IPv4 Address**.
Sebaiknya atur IP tetap (DHCP reservation di router) karena alamat ini ditulis di firmware.

Izinkan port 8000 di firewall Windows, hanya untuk jaringan **Private** (PowerShell *Run as Administrator*):
```powershell
Get-NetConnectionProfile   # pastikan NetworkCategory WiFi = Private
New-NetFirewallRule -DisplayName "Laravel 8000 (ESP32)" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow -Profile Private
```

## 4. Konfigurasi firmware

Isi bagian **"1. RAHASIA"** di awal `esp32-dht22.ino`:
- `WIFI_SSID`, `WIFI_PASSWORD`
- `SERVER_URL` → `http://<IPv4-laptop>:8000/api/ingest/esp32`
- `SOURCE_TOKEN` → token dari `source:create`

**Jangan sampai password WiFi dan token ter-push ke GitHub.** Pilih salah satu:
- **Cara A (disarankan):** salin folder `esp32-dht22` ke folder sketch Arduino di luar repo
  (mis. `Documents\Arduino\esp32-dht22`), isi rahasia di salinan itu, upload dari sana.
  Berkas di repo tetap berisi contoh.
- **Cara B:** biarkan `.ino` berisi contoh, lalu buat berkas `secrets.h` di folder yang sama berisi
  empat `#define` di atas. Bila `secrets.h` ada, nilainya otomatis dipakai; `secrets.h` sudah
  masuk `.gitignore`.

Pengaturan lain (pin, interval, OLED) ada di bagian **"2. PENGATURAN"** di berkas yang sama.

## 5. Upload dan cek

1. Buka `esp32-dht22.ino` di Arduino IDE → Upload.
2. **Serial Monitor 115200 baud.** Contoh log normal:
   ```
   [BOOT] ESP32 DHT22 monitoring ruang server
   [OLED] aktif
   [HTTP] 200  {"temperature":26.4,"humidity":55.1,"ts":1792...}  {"stored":2}
   ```
3. Cek di DBeaver:
   ```sql
   SET time_zone = '+00:00';
   SELECT metric, value, recorded_at, received_at FROM metrics
   WHERE source_id = (SELECT id FROM sources WHERE name = 'esp32-01')
   ORDER BY id DESC LIMIT 6;
   ```

## 6. Perilaku

| Kondisi | Perilaku |
|---|---|
| Normal | Baca DHT22 3× (jeda 2,5 dtk), ambil median, kirim tiap 30 dtk |
| Jam belum sinkron NTP | Tidak mengirim (server menolak stempel waktu tidak valid); OLED STATUS: "Jam belum" |
| WiFi/server putus | Pembacaan disimpan di antrean RAM (maks 20 ≈ 10 menit), dikirim dari yang tertua saat pulih. Bila penuh, data tertua dibuang |
| WiFi gagal > 5 menit | ESP32 restart otomatis |
| Token salah (401/403) | Data tetap diantre; OLED STATUS: "Server TOKEN?" dan tanda `!` |
| Data ditolak permanen (422) | Data itu dibuang, lanjut ke data berikutnya |
| DHT22 gagal dibaca | OLED: "DHT22 ERROR / cek kabel"; tidak ada data dikirim |

Batasan: data dikirim lewat HTTP tanpa TLS di jaringan lokal; data antrean yang tiba > 2 menit
terlambat disimpan dan tampil di grafik, tetapi tidak dievaluasi alarm oleh server.

## 7. Tampilan OLED

Halaman bergantian tiap 5 detik: **SUHU** (animasi termometer) → **KELEMBABAN** (animasi tetes)
→ **STATUS** (WiFi, server, jam WIB / jumlah antrean). Pita atas: ikon WiFi, titik server
(penuh = terkirim), jumlah antrean. Isi bergeser 0–2 px tiap siklus dan kontras rendah untuk
mengurangi burn-in.

## 8. Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| Kompilasi error `multiple definition of setup` | Masih ada berkas lama `app.cpp`/`display.cpp` di folder; hapus, sisakan hanya `esp32-dht22.ino` |
| Serial: `[OLED] tidak ditemukan di alamat 0x3C` | Cek kabel SDA/SCL; coba `OLED_I2C_ADDR 0x3D` di bagian PENGATURAN |
| OLED menyala tetapi gambar bergeser/berantakan | Chip SH1106: ubah `OLED_DRIVER_SH1106 1` |
| `[HTTP] -1` | Server tidak terjangkau: cek `SERVER_URL`, server jalan dengan `--host=0.0.0.0`, firewall port 8000 |
| `[HTTP] 401` | Token salah atau sumber nonaktif |
| `[HTTP] 403` | Token milik host (Telegraf), bukan ESP32 |
| `[HTTP] 422` + "Periksa sinkronisasi NTP" | Jam ESP32 belum benar; cek internet WiFi |
| `[DHT22] pembacaan gagal` | Cek kabel DATA ke GPIO4 dan daya 3V3 |
| Tanpa OLED | Ubah `USE_OLED 0` di bagian PENGATURAN |
