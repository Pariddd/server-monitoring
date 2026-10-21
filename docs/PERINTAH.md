# Perintah Operasional — monitoring-server

Proyek: `D:\SKRIPSI\PROJECT\monitoring-server`
Terakhir diperbarui: 21 Oktober 2026 malam (ditambah perintah pengujian otomatis)
Versi induk di Project claude.ai (`claude/perintah-operasional.md`). Dokumen terkait: `CATATAN-IMPLEMENTASI.md`, `KEPUTUSAN.md`.

> Tanda **[CEK]** = belum dipastikan, cek dulu sebelum dipakai.

---

## 0. Aturan penting (jangan dilanggar)

- Jangan pernah membagikan isi `.env`, token Telegraf, atau token bot Telegram.
- Jangan mengubah `is_enabled` aturan lewat SQL. Selalu lewat halaman **Aturan**. UPDATE SQL melewati logika reset sehingga data masa nonaktif ikut dievaluasi dan memicu notifikasi basi.
- Jangan menekan tombol **Kembalikan ke default** dan jangan menjalankan `AlertRuleSeeder` sebelum siap pengujian resmi, karena keduanya mengaktifkan lagi `mem_high` dan `disk_low`.
- Jangan menjalankan `php artisan serve --host=0.0.0.0`. Dashboard belum punya login, jadi cukup di `127.0.0.1`.
- Di Git Bash, `php` memakai winpty. Kalau output di-pipe (`|`) atau diarahkan ke file (`>`), pakai **`php.exe`**.

---

## 1. Menyalakan sistem (urutan)

### 1.1 Prasyarat
1. Buka **DBngin**, lalu Start MySQL.
2. Pastikan Telegraf jalan (PowerShell **Run as Administrator**):
```powershell
Get-Service telegraf-agent
Start-Service telegraf-agent      # jika Stopped
Restart-Service telegraf-agent    # setelah mengubah telegraf-monitoring.conf
```

### 1.2 Empat proses Laravel (Git Bash, jendela terpisah)
```bash
cd /d/SKRIPSI/PROJECT/monitoring-server

php artisan serve                                   # jendela 1: web + endpoint ingest
php artisan schedule:work                           # jendela 2: evaluator aturan tiap 10 dtk
php artisan queue:work --queue=notifications --sleep=1   # jendela 3: pengirim Telegram
npm run dev                                         # jendela 4: aset frontend (Vite)
```
Dashboard: http://127.0.0.1:8000

### 1.3 Kapan perlu restart
| Yang diubah | Yang perlu dilakukan |
|---|---|
| Blade, JS, CSS biasa | tidak perlu (refresh browser, Ctrl+F5 bila perlu) |
| `vite.config.js`, baris `@source`/`@theme` di `app.css` | hentikan dan jalankan ulang `npm run dev` |
| File PHP yang dipakai job Telegram (mis. `MessageFormatter.php`, `SendTelegramNotification.php`) | hentikan (Ctrl+C) lalu jalankan ulang `queue:work` |
| File PHP lain (controller, evaluator) | tidak perlu (`schedule:work` menjalankan proses baru tiap siklus; `serve` membaca ulang file) |
| `.env` atau file di `config/` | `php artisan config:clear`, lalu restart `queue:work` |
| Config Telegraf | `Restart-Service telegraf-agent` |

---

## 2. Perintah artisan milik proyek

```bash
# Membuat sumber data baru (token tampil SEKALI — simpan, jangan dibagikan)
php artisan source:create <nama> <host|esp32>
php artisan source:create esp32-01 esp32          # contoh untuk ESP32 nanti
php artisan source:create server-monitor host --rotate   # ganti token (token lama tidak berlaku)

# Menjalankan evaluator sekali secara manual (debug)
php artisan alerts:evaluate

# Laporan latensi (waktu UTC)
php artisan alerts:latency --since="2026-10-21 00:00:00"
php artisan alerts:latency --since="2026-10-21 00:00:00" --rule=service_down
php.exe artisan alerts:latency --since="2026-10-21 00:00:00" --csv > latency.csv

# Telegram
php artisan telegram:chat-id      # cek chat ID dari pesan terakhir ke bot
php artisan telegram:test         # kirim pesan uji
```
Catatan: `--since` memakai waktu **UTC** (WIB dikurangi 7 jam).

---

## 3. Perintah bawaan Laravel yang sering dipakai

```bash
php.exe artisan about | grep -i timezone     # harus UTC
php artisan route:list --path=api -v         # endpoint ingest + urutan middleware
php artisan route:list                       # semua route
php artisan db:table alert_rules             # struktur tabel (ganti nama tabel)
php artisan migrate:status
php artisan view:clear                       # bila tampilan Blade aneh/tidak berubah
php artisan config:clear                     # setelah ubah .env / config
php artisan queue:failed                     # daftar job gagal (Laravel)
```

Endpoint ingest (terverifikasi):
- `POST /api/ingest/telegraf` → `Api\IngestTelegrafController` (middleware: `throttle:ingest-ip` → `source.auth:host` → `source.throttle`)
- `POST /api/ingest/esp32` → `Api\IngestEsp32Controller` (middleware: `throttle:ingest-ip` → `source.auth:esp32` → `source.throttle`)

---

## 3a. Pengujian otomatis (Tahap 8)

Prasyarat sekali saja: database tes terpisah.
```sql
CREATE DATABASE servermonitoring_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```bash
php artisan config:clear                 # wajib bila pernah config:cache
php artisan test                         # semua tes (±114 tes)
php artisan test --compact               # ringkas
php artisan test tests/Feature/Alerting  # satu folder
php artisan test --filter="hysteresis"   # tes yang namanya mengandung kata tertentu
php artisan test --order-by=random       # urutan acak (cek tes tidak saling bergantung)
```
- Tes memakai `servermonitoring_test` dan menghapus isinya setiap kali jalan. Database pengembangan `servermonitoring` tidak disentuh (tes dibatalkan bila nama database tidak berakhiran `_test`).
- Tes tidak mengirim pesan Telegram (token palsu, HTTP diblokir/dipalsukan).
- Jalankan `php artisan test` setiap selesai mengubah kode, sebelum commit.

## 4. Log

```bash
tail -f storage/logs/alerts-evaluate.log     # output evaluator (error evaluasi muncul di sini)
tail -n 50 storage/logs/laravel.log          # error aplikasi
```

---

## 5. Uji alert layanan (Spooler)

PowerShell **Run as Administrator**:
```powershell
Stop-Service Spooler     # tunggu ~25–30 dtk → pesan [FIRING] di Telegram
Start-Service Spooler    # tunggu ~5–10 dtk  → pesan [RESOLVED]
```
Spooler dipakai sebagai objek uji terkendali (aman dihentikan), bukan layanan kritis.

---

## 6. Query DBeaver

Jalankan semua dengan **Alt+X** (Execute script). Ctrl+Enter hanya menjalankan query di bawah kursor.

```sql
-- Wajib di awal sesi: tampilkan semua kolom waktu dalam UTC
SET time_zone = '+00:00';

-- Aturan
SELECT id, `key`, source_type, metric, operator, threshold, recover_operator,
       recover_threshold, for_seconds, cooldown_seconds, is_enabled
FROM alert_rules ORDER BY id;

-- State per aturan/tag
SELECT s.*, r.`key`, r.is_enabled
FROM alert_states s JOIN alert_rules r ON r.id = s.alert_rule_id
ORDER BY s.id;

-- Event terbaru
SELECT * FROM alert_events ORDER BY id DESC LIMIT 10;

-- Notifikasi terbaru
SELECT * FROM alert_notifications ORDER BY id DESC LIMIT 10;

-- Notifikasi gagal
SELECT id, kind, attempts, last_error, t_queued
FROM alert_notifications WHERE status = 'failed' ORDER BY id DESC;

-- Sampel metrik terbaru dari H1
SELECT metric, tag, value, recorded_at, received_at
FROM metrics WHERE source_id = 1 ORDER BY id DESC LIMIT 20;

-- Kapan sumber terakhir mengirim data
SELECT id, name, type, is_active, last_seen_at FROM sources;
```

Untuk data skripsi, pakai kolom waktu **DATETIME** (`t_breach`, `t_detected`, `t_queued`, `t_sent`, `recorded_at`, `received_at`). Jangan pakai `created_at`/`updated_at`.

---

## 7. Git

```bash
php artisan test           # pastikan semua tes lolos sebelum commit
git status                 # pastikan .env dan inti*.txt TIDAK ada di daftar
git add -A
git status
git commit -m "Pesan commit"
git log --oneline -5
```

---

## 8. Sebelum pengujian resmi (checklist)

- [ ] Ruang bebas disk C: dan D: di atas 12%, pemakaian memori di bawah 85%.
- [ ] Aktifkan `mem_high` dan `disk_low` **lewat halaman Aturan**.
- [ ] Backup database (DBeaver → klik kanan database `servermonitoring` → Tools → Dump database), simpan di D:.
- [ ] Semua proses (bagian 1) berjalan; `queue:work` tidak boleh mati.
- [ ] Catat waktu mulai (UTC) untuk `--since`.
- [ ] Minimal 20 percobaan per skenario, lalu ekspor `alerts:latency --csv`.

---

## 9. Telegraf (manual) [CEK]

```powershell
# [CEK] lokasi telegraf.exe — sesuaikan path
& "D:\SKRIPSI\PROJECT\telegraf\telegraf.exe" --config "D:\SKRIPSI\PROJECT\telegraf\telegraf-monitoring.conf" --test
```
`--test` hanya mengumpulkan sekali dan mencetak hasilnya, tanpa mengirim ke Laravel.
