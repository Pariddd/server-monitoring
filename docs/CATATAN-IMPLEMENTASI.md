# Catatan Implementasi Sistem (as-built) — sumber data untuk penulisan skripsi

Terakhir diperbarui: 21 Oktober 2026 malam (Tahap 7 selesai; skema DB, operator aturan, endpoint, dan perilaku zona waktu diverifikasi).
Pendamping: `KEPUTUSAN.md` (keputusan desain dan parameter aturan) dan `PERINTAH.md` (perintah operasional).
Versi induk disimpan di Project claude.ai (`claude/catatan-implementasi-sistem.md`); salinan di repo `docs/` diperbarui setiap tahap selesai.

## Cara membaca dokumen ini
- **[V]** = terverifikasi dari output nyata (terminal, query DB, Telegram) selama pengembangan.
- **[R]** = rancangan/implementasi yang dibuat, belum diuji formal.
- **[CEK]** = detail yang belum dipastikan dari kode/DB; WAJIB dicek sebelum ditulis di skripsi.
- Semua angka pengukuran di sini adalah **pengukuran informal selama pengembangan**, BUKAN hasil pengujian resmi Bab 4. Hasil resmi harus diambil ulang (≥ 20 percobaan per skenario, `alerts:latency --since=... --csv`).

---

## 1. Lingkungan dan versi [V]
| Komponen | Versi / lokasi |
|---|---|
| OS host H1 | Windows (PC pribadi), terminal Git Bash + PowerShell (di Git Bash, `php` di-alias ke winpty; pakai `php.exe` bila output di-pipe) |
| PHP | 8.4.25 ZTS, `D:\php84`; ekstensi `pdo_mysql`; OPcache aktif (`opcache.enable=1`, `opcache.enable_cli=1`, `revalidate_freq=0`); CA bundle `curl.cainfo`/`openssl.cafile` = `D:/php84/extras/ssl/cacert.pem` |
| Composer | 2.8.6 |
| Laravel | 13.38.0, proyek `D:\SKRIPSI\PROJECT\monitoring-server`; `config/app.php` `'timezone' => 'UTC'` (diverifikasi `php artisan about`) |
| Database | MySQL via DBngin (database `servermonitoring`, InnoDB, utf8mb4_unicode_ci), klien DBeaver; koneksi Laravel `timezone '+00:00'` |
| Frontend | Blade + Tailwind CSS v4 (CSS-first `@theme`), Vite 8, laravel-vite-plugin 3.1, Alpine.js 3.17.4, Chart.js 4.5.1 (tree-shaken), font Barlow Semi Condensed + IBM Plex Mono (@fontsource) |
| Agen | Telegraf 1.40.2, service Windows `telegraf-agent`, config `D:\SKRIPSI\PROJECT\telegraf\telegraf-monitoring.conf` (ACL dibatasi `icacls`) |
| Notifikasi | Telegram Bot API |
| Tidak dipakai | Telegraf lama `D:\telegraf` (1.37, output PostgreSQL, service `Telegraf`) — dimatikan; Grafana/InfluxDB — dihapus dari skripsi |

## 2. Arsitektur dan alur data [V]
```
Telegraf (H1, tiap 10 dtk, offset 5 dtk)
   └─ HTTP POST JSON batch + Bearer token ──► POST /api/ingest/telegraf
                                               ├─ simpan ke tabel metrics (idempoten)
                                               └─ perbarui sources.last_seen_at
Scheduler Laravel (tiap 10 dtk) ──► alerts:evaluate ──► state machine per (aturan, sumber, tag)
                                               └─ transisi → alert_events (+ alert_notifications untuk FIRING/RESOLVED/REPEAT)
Queue worker (antrean notifications) ──► SendTelegramNotification ──► Telegram Bot API
Browser ──► dashboard (polling JSON /data/...) — tidak memengaruhi evaluasi
ESP32 + DHT22 ──► POST /api/ingest/esp32 (route & controller ada; firmware BELUM dibangun)
```
Route API terverifikasi (`php artisan route:list --path=api`): `POST api/ingest/esp32` → `Api\IngestEsp32Controller`; `POST api/ingest/telegraf` → `Api\IngestTelegrafController`.
Proses yang harus berjalan saat pengembangan: `php artisan serve` (hanya 127.0.0.1), `php artisan schedule:work`, `php artisan queue:work --queue=notifications --sleep=1`, `npm run dev`, service `telegraf-agent`, MySQL (DBngin).

## 3. Konfigurasi Telegraf [V]
- `interval = "10s"`, `collection_offset = "5s"` (mencegah tabrakan dengan evaluator di detik :00/:10), `flush_interval = "1s"`.
- Input: `win_perf_counters` → `win_cpu` instance `_Total` counter `Percent_Processor_Time`; `win_disk` objek LogicalDisk counter `Percent_Free_Space` (per volume: C:, D:); `inputs.mem` → `used_percent`; `win_services` → Spooler, WSearch (state 4 = Running, 1 = Stopped).
- Output: `outputs.http`, format JSON batch, `json_timestamp_units = "1s"`, `non_retryable_statuscodes = [400, 413, 422]`, header `Authorization: Bearer <token>`.
- Alasan interval 10 dtk: aturan terpendek 20 dtk (layanan) dan heartbeat host 30 dtk membutuhkan ≥ 2–3 sampel dalam jendela.

## 4. Model data [V — dari `php artisan db:table`, 21 Okt 2026]

### `sources` (10 kolom)
`id`, `name` varchar(64) UNIQUE, `type` enum('host','esp32'), `token_hash` char(64) UNIQUE (SHA-256 dari token `pm_` + 64 hex; token asli hanya ditampilkan sekali oleh `source:create`), `ip_address` varchar(45) null, `ip_updated_at` datetime null, `last_seen_at` datetime(3) null, `is_active` tinyint(1) default 1, `created_at`, `updated_at`. Index `(type, is_active)`.
Sumber: id 1 `server-monitor` (host H1, aktif); id 2 `test-host` (uji, nonaktif).

### `metrics` (7 kolom; 4,86 MB per 21 Okt)
`id`, `source_id` FK→sources (cascade), `metric` varchar(32), `tag` varchar(64), `value` double, `recorded_at` datetime (detik, waktu data), `received_at` datetime(3) (waktu diterima server).
Index: UNIQUE `metrics_series_time_unique (source_id, metric, tag, recorded_at)` → ingest idempoten via `insertOrIgnore`; `metrics_source_metric_time_index (source_id, metric, recorded_at)`; `metrics_recorded_at_index (recorded_at)`.
Nama metrik internal: `cpu_usage`, `mem_used_pct`, `disk_free_pct` (tag = volume), `service_state` (tag = nama layanan), `temperature`, `humidity`.

### `alert_rules` (15 kolom)
`id`, `key` varchar(32) UNIQUE, `name` varchar(100), `kind` enum('threshold','heartbeat'), `source_type` enum('host','esp32'), `metric` varchar(32) null, `operator` enum('>','<','!=') null, `threshold` double null, `recover_operator` enum('<','>','==') null, `recover_threshold` double null, `for_seconds` int unsigned, `cooldown_seconds` int unsigned default 600, `is_enabled` tinyint(1) default 1, `created_at`, `updated_at`.
Nilai default disimpan di `config/monitoring.php` (key `rules`), dibaca oleh `AlertRuleSeeder`; label memakai templat `:t` (ambang), `:dur` (" selama N dtk" bila for > 0), `:f`.

Isi aturan (21 Okt 2026) [V]:
| id | key | sumber | metrik | kondisi pemicu | kondisi pulih | for (dtk) | cooldown (dtk) | aktif |
|---|---|---|---|---|---|---|---|---|
| 1 | cpu_high | host | cpu_usage | > 85 | < 80 | 60 | 600 | 1 |
| 2 | mem_high | host | mem_used_pct | > 90 | < 85 | 60 | 600 | 0 (sengaja, pengembangan) |
| 3 | disk_low | host | disk_free_pct | < 10 | > 12 | 60 (sebelumnya 0; diubah 21 Okt) | 600 | 0 (sengaja, pengembangan) |
| 4 | service_down | host | service_state | != 4 | == 4 | 20 | 600 | 1 |
| 5 | temp_high | esp32 | temperature | > 27 | < 26 | 60 | 600 | 1 |
| 6 | humidity_high | esp32 | humidity | > 60 | < 58 | 60 | 600 | 1 |
| 7 | heartbeat_host | host | – (last_seen_at) | tanpa data > 30 dtk | data masuk lagi | 30 | 600 | 1 |
| 8 | heartbeat_esp32 | esp32 | – (last_seen_at) | tanpa data > 90 dtk | data masuk lagi | 90 | 600 | 1 |
Catatan: `service_state` 4 = Running (kode state Windows Service Control Manager via Telegraf `win_services`). Nama disk_low di DB: "Ruang disk bebas < 10% selama 60 dtk"; default config `for_seconds` disk_low juga 60.

### `alert_states` (13 kolom)
`id`, `alert_rule_id` FK (cascade), `source_id` FK (cascade), `tag` varchar(64), `state` enum('NORMAL','PENDING','FIRING','RESOLVED') default NORMAL, `state_since` datetime(3), `breach_at` datetime, `last_value` double, `last_sample_at` datetime (DETIK, tanpa milidetik), `last_notified_at` datetime(3), `last_evaluated_at` datetime(3), `created_at`, `updated_at`.
UNIQUE `(alert_rule_id, source_id, tag)`; index `state`.
Baris (21 Okt): 1 cpu_high; 2 mem_high; 3 disk_low C:; 4 disk_low D:; 5 service_down Spooler; 6 service_down WSearch; 7 heartbeat_host server-monitor; 8 heartbeat_host test-host.

### `alert_events` (7 kolom) — satu baris per transisi state
`id`, `alert_state_id` FK (cascade), `from_state`, `to_state` (enum state), `value` double, `t_breach` datetime (detik), `t_detected` datetime(3). Index `t_detected`. TIDAK ada created_at/updated_at.
Semantik `t_breach` = **waktu data pemicu transisi**: untuk PENDING/FIRING = awal pelanggaran; untuk RESOLVED = waktu sampel pulih. `t_detected` = waktu event dibuat oleh evaluator.

### `alert_notifications` (15 kolom)
`id`, `alert_state_id` FK (cascade), `alert_event_id` FK null (set null), `kind` enum('FIRING','RESOLVED','REPEAT'), `status` enum('queued','sent','failed') default queued, `attempts` smallint, `message` text, `t_breach` datetime, `t_detected` datetime(3), `t_queued` datetime(3), `t_sent` datetime(3) null, `telegram_message_id` bigint null, `last_error` text null, `created_at`, `updated_at`. Index `(status, t_queued)`.
Kolom waktu untuk segmen latensi: B = `t_detected` − (`t_breach` + for); C ≈ `t_queued` − `t_detected` (orde ms); D = `t_sent` − mulai kirim.

### Konvensi waktu [V]
- Zona waktu aplikasi = UTC; koneksi MySQL Laravel = `+00:00`.
- Kolom `DATETIME` (`t_*`, `state_since`, `breach_at`, `last_sample_at`, `last_seen_at`, `recorded_at`, `received_at`) disimpan apa adanya = **UTC**. Inilah SATU-SATUNYA sumber data waktu untuk skripsi.
- Kolom `TIMESTAMP` (`created_at`, `updated_at` otomatis Laravel) dikonversi MySQL menurut zona waktu sesi penulis/pembaca. DBeaver memakai zona sesi sistem Windows (WIB), sehingga menampilkannya +7 jam (terbukti: setelah `SET time_zone = '+00:00'`, `created_at` notif 57–59 sama dengan `t_detected` hingga detik).
- Baris yang ditulis SEBELUM koneksi diberi `timezone '+00:00'` (20 Okt) tersimpan meleset 7 jam di kolom TIMESTAMP (string UTC ditafsirkan sebagai WIB). Kolom DATETIME tidak terdampak.
- Kesimpulan: JANGAN memakai `created_at`/`updated_at` sebagai data penelitian. Di DBeaver jalankan `SET time_zone = '+00:00';` di awal sesi agar tampilan konsisten.
- Model berkolom milidetik memakai `$dateFormat = 'Y-m-d H:i:s.v'`; `AlertState` meng-cast `last_sample_at` ke datetime.

## 5. Ingest dan keamanan [V]
- Endpoint: `POST /api/ingest/telegraf`, `POST /api/ingest/esp32`.
- Middleware `source.auth` (`AuthenticateSource`): cocokkan hash token Bearer dengan `sources.token_hash`.
- Rate limit: `ingest-ip` 300/menit, `ingest-source` 120/menit.
- Respons API selalu JSON untuk `api/*`; status 400/413/422 tidak di-retry Telegraf.
- `TelegrafMapper` memetakan metrik Telegraf ke metrik internal (`config/monitoring.php`).
- `SourcePresence` memperbarui `last_seen_at`, mengabaikan IP loopback (akibatnya IP H1 tidak terisi otomatis).
- Dashboard: form dilindungi CSRF; **dashboard belum memiliki autentikasi** (batasan); `serve` hanya di 127.0.0.1.
- Token tidak pernah dibagikan di chat; `.env` tidak di-commit.

## 6. Mesin status (state machine) [V]
- State: NORMAL → PENDING → FIRING → RESOLVED (→ NORMAL).
- Setiap sampel baru diproses berurutan; durasi tahan (`for_seconds`) diukur dengan **waktu data** (`recorded_at`), bukan waktu server. `for_seconds = 0` → langsung FIRING (didukung, tetapi per 21 Okt tidak ada aturan yang memakai 0).
- Hysteresis (ambang pulih) hanya berlaku saat FIRING.
- Heartbeat berbasis `sources.last_seen_at`, bukan sampel.
- Cooldown → pesan REPEAT selama masih FIRING.
- Data terlambat (lebih tua dari sampel terakhir yang diproses) diabaikan. Lookback = `max(for + 30, 120)` dtk.
- Penanda posisi: `alert_states.last_sample_at`; evaluator hanya memproses sampel yang lebih baru.
- Aturan nonaktif → tidak dievaluasi; saat diaktifkan kembali lewat halaman Aturan, state di-reset NORMAL dan `last_sample_at` = waktu aktivasi dipotong ke detik (`Y-m-d H:i:s`, karena kolom DATETIME tanpa milidetik dan MySQL membulatkan) — `RuleController::resetStates()`, dipasang 21 Okt.
- Penjadwal: `Schedule::command('alerts:evaluate')->everyTenSeconds()->withoutOverlapping(1)->appendOutputTo(storage_path('logs/alerts-evaluate.log'))`.

## 7. Notifikasi Telegram [V]
- Job `SendTelegramNotification` dikirim `afterCommit`, antrean `notifications`, `retryUntil` 30 menit, backoff `[5, 15, 30, 60, 120, 300]` dtk.
- HTTP 429 → menunggu `retry_after`; 4xx lain → gagal permanen; token disamarkan (`redact()`) di pesan error.
- Semantik pengiriman **at-least-once** (duplikat mungkin, hilang tidak, kecuali melewati `retryUntil`).
- Format pesan (WIB): `[FIRING] <nama aturan>` / `Sumber: <host> (<tag>)` / `Nilai: ...` / `Waktu data melewati ambang: ... WIB` (RESOLVED: `Waktu data pulih`).
- Perintah: `telegram:chat-id`, `telegram:test`.

## 8. Definisi latensi [V]
- Segmen: **A** ingest (sampel diambil → diterima Laravel; `received_at` − `recorded_at`), **B** evaluasi (→ event dibuat, `t_detected`), **C** antrean (`t_queued` → job mulai), **D** kirim (→ `t_sent`).
- Latensi sistem = B + C + D = total (`t_sent` − `t_breach`) − durasi tahan (`for_seconds`).
- `alerts:latency {--since=} {--rule=} {--csv}`: mengecualikan REPEAT dan event kondisi awal; p95 metode nearest-rank; waktu UTC.

## 9. Hasil pengukuran informal (pengembangan, BUKAN Bab 4) [V]
| Besaran | Nilai | Konteks |
|---|---|---|
| Latensi sistem (median kasar) | ≈ 7,4 dtk (B ≈ 5,9; C ≈ 5 ms; D ≈ 1,5) | `alerts:latency`, 20 Okt 2026 |
| Contoh 21 Okt (notif 58 FIRING) | ≈ 7,5 dtk (B 6,0; D 1,5) | Spooler stop, tahan 20 dtk |
| Contoh 21 Okt (notif 59 RESOLVED) | ≈ 7,0 dtk (B 5,7; D 1,3) | Spooler start |
| Waktu respons ingest | 50–77 ms | setelah OPcache aktif |
| Durasi satu evaluasi | ≈ 430 ms | proses PHP baru tiap jalan |
| Jitter timestamp Telegraf | ± 1–2 dtk | dari data `recorded_at` |
| Variasi ruang bebas C: | 0,07–4,4 % (D: stabil) | kemungkinan pagefile berubah ukuran; alasan disk_low diberi durasi 60 dtk |

## 10. Bug yang ditemukan dan diperbaiki (bahan pembahasan Bab 4) [V]
| # | Gejala | Akar masalah | Perbaikan |
|---|---|---|---|
| 1 | Telegraf: "outputs.http did not complete within flush interval" | OPcache mati, respons ingest lambat | OPcache + `enable_cli=1` → 50–77 ms |
| 2 | Evaluator error `Call to a member function gt() on string`, tidak terlihat | cast `last_sample_at` hilang; output scheduler dibuang ke NUL | tambah cast + `CarbonImmutable::parse`; `appendOutputTo` log |
| 3 | Waktu deteksi bervariasi 1–11 dtk | Telegraf dan evaluator berjalan di detik yang sama | `collection_offset = "5s"` → ≈ 6 dtk konsisten |
| 4 | cURL error 60 ke Telegram | CA bundle tidak terpasang | `cacert.pem` + `curl.cainfo` |
| 5 | FIRING/RESOLVED berulang (looping) pada memori | ambang pulih 95 > ambang 90 | validasi ambang pulih di halaman Aturan |
| 6 | Notifikasi basi (56–57) saat aturan diaktifkan kembali | `last_sample_at` tidak digeser saat aktivasi → sampel masa nonaktif dievaluasi ulang | `resetStates()` (21 Okt), uji ulang lolos |
| 7 | Notifikasi 53 (heartbeat RESOLVED) `failed` 2 jam kemudian | worker antrean mati > 30 menit, lewat `retryUntil` | sesuai desain; jadikan batasan + worker permanen (Tahap 9) |
| 8 | State `disk_low` membeku FIRING saat nonaktif | dinonaktifkan via UPDATE SQL (lewati logika reset) | dirapikan ke NORMAL; status aturan wajib via halaman Aturan |
| 9 | `created_at` tampak +7 jam dari `t_*` di DBeaver; baris lama tidak | perbedaan TIMESTAMP vs DATETIME + koneksi Laravel belum `+00:00` sebelum 20 Okt | bukan bug aplikasi; data penelitian memakai kolom DATETIME `t_*` |
| 10 | Satu sampel bisa terlewat tepat sesudah aturan diaktifkan | `last_sample_at` (DATETIME detik) diisi nilai milidetik → dibulatkan ke atas oleh MySQL | nilai dipotong ke detik di `resetStates()` |

## 11. Dashboard [V]
- Halaman: Ringkasan (`/`), Detail host (`/servers/{name}`), Aturan (`/rules`, PUT simpan, POST reset). Data JSON: `/data/summary`, `/data/servers/{name}`.
- Polling dipilih pengguna (10 dtk/30 dtk/60 dtk/5 mnt/10 mnt, default 10 dtk, disimpan di localStorage); tidak memengaruhi alerting. Kontrol polling disembunyikan di halaman tanpa polling (Aturan, via `@section('no_polling', '1')`).
- Waktu ditampilkan WIB (offset tetap +7 jam); "Data terakhir" = waktu absolut `last_seen_at`.
- Grafik: celah data digambar sebagai area arsir "DOWN"; garis ambang di bawah data; label hanya pada pelanggaran terakhir; sumbu selaras langkah WIB.
- Aturan nonaktif tampil NONAKTIF + pemberitahuan.

## 12. Validasi halaman Aturan [V]
- Ambang pulih < ambang (aturan ">"), > ambang (aturan "<"); rentang min/maks per aturan.
- Durasi 0 atau ≥ 10 dtk; heartbeat ≥ `min_for` (host 20, ESP32 60).
- Cooldown 60–86.400 dtk.
- Catatan: validasi menerima nilai durasi apa pun ≥ 10 (mis. salah ketik 54 tetap tersimpan) — periksa nilai setelah menyimpan.

## 13. Log pengujian fungsional Tahap 7e (21 Okt 2026, informal) [V]
| Uji | Hasil |
|---|---|
| 1 Sakelar memori & disk tampil Nonaktif | lolos |
| 2 Memori 90/95 ditolak, DB tetap 90/85/0 | lolos |
| 3 Durasi CPU 5 dtk ditolak | lolos |
| 4 Kelembaban 62 tersimpan, kembali ke 60 | lolos |
| 5 Aturan layanan nonaktif + Spooler stop → tanpa event | lolos |
| 5 (temuan) Aktifkan kembali → replay event 121–123, notif 56–57 | bug #6 |
| 5b Setelah perbaikan: nonaktif → stop → start → aktif → tanpa event/notif; `state_since` 22:05:48.902 UTC | lolos |
| Pelengkap: aturan aktif kembali mendeteksi kejadian baru (event 124–126, notif 58–59 sent) | lolos |

## 14. Batasan dan hal belum dikerjakan (untuk Bab 5)
- Autentikasi dashboard; IP H1 tidak terisi otomatis (loopback); riwayat IP; pembersihan data lama (pruning); halaman semua event; host kedua (VM Windows Server baru, bukan ECORP-DC).
- Pengiriman at-least-once; data terlambat diabaikan; jitter timestamp ± 2 dtk; hysteresis disk berbasis persen (bukan GB); durasi tahan disk 60 dtk berarti deteksi disk penuh terlambat ≤ 1 menit.
- Notifikasi gagal bila worker mati > 30 menit; status aturan harus diubah lewat halaman Aturan; `artisan serve` single-thread (tidak untuk fase pengukuran).
- Layanan yang dipantau (Spooler, WSearch) adalah objek uji terkendali, bukan layanan kritis server.

## 15. Status tahap
- Selesai: 1–6 (fondasi, ingest, Telegraf, alerting, Telegram, latensi), 7a–7e (dashboard + Aturan).
- Berikutnya: commit Tahap 7 → Tahap 8 (feature test) → Tahap 9 (proses permanen) → ESP32 → persiapan & pengujian resmi.

## 16. Temuan terbuka
- Tidak ada (semua temuan 21 Okt ditutup: zona waktu, presisi `last_sample_at`, operator aturan, endpoint, durasi disk_low).
- Sisa [CEK]: lokasi `telegraf.exe` untuk tes manual (lihat `PERINTAH.md` §9).
