# Catatan Implementasi Sistem (as-built) — sumber data untuk penulisan skripsi

Terakhir diperbarui: 21 Oktober 2026 malam (Tahap 8 — pengujian otomatis — dibuat; 2 bug baru ditemukan dan diperbaiki).
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
- Rate limit: `ingest-ip` 300/menit per IP (route `throttle:ingest-ip`, berjalan SEBELUM autentikasi) dan `ingest-source` 120/menit per sumber (middleware `source.throttle` = `App\Http\Middleware\ThrottleIngestSource`, berjalan SESUDAH `source.auth`). Urutan per route: `throttle:ingest-ip` → `source.auth:<tipe>` → `source.throttle` (diverifikasi `php artisan route:list --path=api -v`).
- Permintaan dengan token salah tetap dihitung ke kuota IP (300 percobaan gagal per menit memblokir IP itu selama sisa menit tersebut).
- Respons API selalu JSON untuk `api/*`; status 400/413/422 tidak di-retry Telegraf.
- `TelegrafMapper` memetakan metrik Telegraf ke metrik internal (`config/monitoring.php`).
- `SourcePresence` memperbarui `last_seen_at` dan IP sumber. Sejak 22 Okt 2026: bila pengirim loopback (`127.0.0.1`/`::1`, yaitu Telegraf di komputer server sendiri), IP yang disimpan = **IP jaringan utama server** dari `App\Services\ServerAddress` (socket UDP "terhubung" ke `8.8.8.8:53` lalu `stream_socket_get_name` → alamat adapter pemegang default route, mis. WiFi `192.168.1.36`, bukan VirtualBox `192.168.56.1`; UDP `connect()` tidak mengirim paket; hasil di-cache 60 dtk). Server offline (tanpa rute) → IP terakhir dipertahankan; `127.0.0.1` tidak pernah disimpan. Dashboard menampilkan IP saja (keputusan pengguna, tanpa label). Sebelumnya IP loopback diabaikan sehingga IP H1 kosong ("IP belum diketahui").
- Dashboard: form dilindungi CSRF; **dashboard belum memiliki login** (batasan). Sejak 21 Okt (persiapan ESP32): semua route web (`/`, `/servers/*`, `/rules`, `/data/*`) melewati middleware `RestrictToLocal` (ditambahkan ke grup `web` di `bootstrap/app.php`) → hanya alamat di `DASHBOARD_ALLOWED_IPS` (default `127.0.0.1,::1`, mendukung CIDR) yang dapat membuka dashboard; selain itu 403. IP diambil dari REMOTE_ADDR (tanpa trusted proxy), sehingga `X-Forwarded-For` palsu tidak bisa melewatinya. API `/api/ingest/*` tidak terpengaruh (dilindungi token). Dengan ini server boleh dijalankan `php artisan serve --host=0.0.0.0` agar ESP32 di LAN dapat mengirim data.
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
- **Sejak 24 Okt 2026** (perbaikan temuan tinjauan #1; kolom baru `alert_notifications.t_started` datetime(3), diisi di awal SETIAP percobaan job, ditimpa saat retry):
  - **A** ingest = `received_at` − `t_breach` (sampel pelanggaran diterima Laravel; informatif, bagian dari B).
  - **B** evaluasi = `t_queued` − (`t_breach` + durasi tahan) (evaluator mendeteksi + baris notifikasi dibuat).
  - **C** antrean = `t_started` − `t_queued` (tunggu worker, termasuk jeda retry bila ada).
  - **D** kirim = `t_sent` − `t_started` (HTTP ke Telegram pada percobaan yang berhasil; `t_sent` = saat API Telegram menerima, BUKAN saat pesan tampil di HP — batasan, tulis di Bab 3).
  - Latensi sistem = B + C + D = total (`t_sent` − `t_breach`) − durasi tahan (`for_seconds`); identitas ini dikunci tes.
  - Notifikasi lama (sebelum 24 Okt, `t_started` null): C dan D tidak dihitung, `alerts:latency` memberi peringatan.
- Sebelum 24 Okt: kode menghitung C = `t_queued − t_detected` (±ms) dan D = `t_sent − t_queued` (gabungan tunggu worker + HTTP). Angka informal §9 "C ≈ 5 ms, D ≈ 1,5 dtk" memakai definisi LAMA ini — jangan dikutip sebagai pemisahan antrean vs kirim.
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
Bug #1–#10 ditemukan lewat pengujian manual/operasional; #11 lewat tinjauan kode; #12 lewat pengujian otomatis Tahap 8.
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
| 11 | `last_sample_at` tidak bisa diisi massal; atribut fiktif `datetime` justru fillable (laten, belum berdampak) | salah tulis `'last_sample_at' => 'datetime'` di `$fillable` `AlertState` | diganti `'last_sample_at'` (ditemukan saat membaca kode untuk Tahap 8) |
| 12 | Kuota 120/menit ternyata dibagi semua sumber dari IP yang sama, bukan per sumber | Laravel selalu mengurutkan `ThrottleRequests` sebelum middleware biasa, sehingga `throttle:ingest-source` berjalan sebelum `source.auth` dan tidak mengenali sumber (kunci jatuh ke IP) | middleware baru `ThrottleIngestSource` (membungkus `ThrottleRequests` tanpa mewarisinya) + alias `source.throttle`; ditemukan oleh tes otomatis rate limit |

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
- Autentikasi dashboard; IP server = IP adapter pemegang default route (bila ada dua jalur, mis. WiFi + LAN kabel, yang tampil adalah pilihan rute Windows); riwayat IP; pembersihan data lama (pruning); halaman semua event; host kedua (VM Windows Server baru, bukan ECORP-DC).
- Pengiriman at-least-once; data terlambat diabaikan; jitter timestamp ± 2 dtk; hysteresis disk berbasis persen (bukan GB); durasi tahan disk 60 dtk berarti deteksi disk penuh terlambat ≤ 1 menit.
- Notifikasi gagal bila worker mati > 30 menit; status aturan harus diubah lewat halaman Aturan; `artisan serve` single-thread (tidak untuk fase pengukuran).
- Layanan yang dipantau (Spooler, WSearch) adalah objek uji terkendali, bukan layanan kritis server.

## 15. Status tahap
- Selesai: 1–6 (fondasi, ingest, Telegraf, alerting, Telegram, latensi), 7a–7e (dashboard + Aturan; di-commit dan di-push ke GitHub 21 Okt).
- Tahap 8 (pengujian otomatis): 114 tes lolos di H1 (§17); di-commit dan di-push (dba81f1). Per 22 Okt: 139 tes lolos di H1.
- Kondisi H1 untuk host kedua (22 Okt): RAM total 8 GB (terpakai ±90–95%); VM Windows Server untuk host kedua BELUM dibuat.
- Tahap 9 (proses permanen) DITUNDA sampai menjelang semhas/sidang (lihat KEPUTUSAN.md); selama sempro sistem dijalankan manual.
- Fase ESP32 dimulai 21 Okt malam: sisi server disiapkan (pembatasan dashboard lokal); firmware + OLED dibuat dan lolos kompilasi (§18); menunggu uji di perangkat.
- Berikutnya: firmware ESP32 → (menjelang semhas) Tahap 9 → persiapan & pengujian resmi.
- **22 Okt 2026: fokus pindah ke Judul 1** (resource server saja) karena DHT22 rusak. Kode ESP32 tidak dihapus; dimatikan dengan saklar `MONITORING_ENVIRONMENT_ENABLED=false` (§19). Kembali ke Judul 2 hanya boleh sebelum naskah proposal diserahkan untuk sempro.

## 16. Temuan terbuka
- Sisa [CEK]: lokasi `telegraf.exe` untuk tes manual (lihat `PERINTAH.md` §9).
- `alerts:latency` memakai `alert_states.created_at` (TIMESTAMP) untuk membuang "kondisi awal". State yang dibuat sebelum koneksi `+00:00` (semua state 1–8, 20 Okt) tersimpan 7 jam lebih awal di kolom itu, sehingga filter kondisi awal lebih longgar untuk data 20 Okt. Tidak berdampak pada pengujian resmi yang memakai `--since` setelah tanggal itu. Opsi perbaikan (belum dilakukan): koreksi satu kali `created_at` state lama, atau ganti acuan ke kolom DATETIME.
- Token/chat Telegram kosong diperlakukan sebagai error sementara (job diulang sampai 30 menit), bukan gagal permanen — disengaja: bila `.env` diperbaiki dan worker di-restart dalam 30 menit, notifikasi tertunda tetap terkirim.

- **APP_DEBUG=true terlihat dari jaringan** (ditemukan 22 Okt dari respons curl): respons error API memuat `exception`, `file`, dan `trace` lengkap (path `D:\SKRIPSI\...`, versi framework). Karena server berjalan `--host=0.0.0.0`, siapa pun di WiFi yang sama dapat melihatnya (CWE-209, OWASP A05 Security Misconfiguration). Header `X-Powered-By: PHP/8.4.25` juga terbuka. Saran: `APP_DEBUG=false` saat server dibuka ke jaringan (log error tetap di `storage/logs/laravel.log`), `expose_php = Off` di php.ini. Status: menunggu keputusan pengguna.

- **Tinjauan kode 24 Okt 2026** (#1 dan #2 DIPERBAIKI 24 Okt malam, disetujui pengguna; #3 dicatat sebagai definisi; #4 DIBUAT 24 Okt — panel Kesehatan sistem, §20; #5 menunggu keputusan):
  1. Definisi segmen latensi C/D di §8 tidak sama dengan kode `alerts:latency`: kode menghitung C = `t_queued − t_detected` (pembuatan baris notifikasi, ±ms) dan D = `t_sent − t_queued` (MENCAKUP tunggu worker antrean + HTTP Telegram). Tidak ada stempel waktu "job mulai". Usulan: kolom `t_started` diisi di awal `SendTelegramNotification::handle()`.
     → Diperbaiki: `t_started` + definisi baru di §8.
  2. `SourcePresence::touch()` dipanggil walau seluruh metrik dalam satu kiriman ditolak (mis. jam host salah > 60 dtk ke depan / > 24 jam ke belakang) → `last_seen_at` tetap segar, heartbeat tidak FIRING, aturan ambang tidak mendapat sampel = kegagalan diam (silent failure). Usulan: perbarui kehadiran hanya bila ≥ 1 baris valid, dan catat peringatan di log bila ada yang ditolak.
     → Diperbaiki di `IngestTelegrafController`: `touch()` hanya bila ada ≥ 1 metrik valid (duplikat tetap dihitung valid); `Log::warning` maks 1×/menit/sumber (kunci cache `ingest:rejected-logged:{id}`). ESP32 tidak perlu diubah (stempel waktu salah sudah 422 sebelum `touch()`). Dikunci tes: host yang terus mengirim data berjam salah → heartbeat FIRING.
  3. `t_sent` = waktu API Telegram menerima pesan, bukan waktu pesan tampil di HP penerima. Harus ditulis sebagai definisi/batasan di Bab 3; opsional validasi sebagian dengan ekspor Telegram Desktop.
  4. Tidak ada pemantauan diri: bila `schedule:work` atau `queue:work` mati, dashboard tetap tampak normal dan alarm tidak pernah terkirim. Usulan: panel kesehatan (evaluasi terakhir, notifikasi tertahan di antrean + umurnya, notifikasi gagal 24 jam).
  5. Tabel `metrics` tumbuh tanpa batas (±6 baris/10 dtk/host ≈ 51.840 baris/hari/host) sementara disk D: hampir penuh. Usulan: perintah retensi terjadwal (ekspor data uji dulu).

## 17. Pengujian otomatis (Tahap 8) [V — lolos di H1 dan di lingkungan replika]
- Kerangka: Pest 5.3.3 (PHPUnit 13.4.3). Jalankan: `php artisan test`.
- Database tes: MySQL terpisah `servermonitoring_test` (bukan SQLite) agar perilaku DATETIME/presisi/enum sama dengan sistem. `RefreshDatabase` memigrasi ulang database tes; tiap tes dalam transaksi yang di-rollback.
- Pengaman (di `tests/TestCase.php`): tes dibatalkan sebelum migrasi bila database bukan `*_test` (diverifikasi: database pengembangan tetap utuh); token/chat Telegram palsu; `Http::preventStrayRequests()` sehingga tidak ada request keluar yang tidak dipalsukan.
- Jam dibekukan (`freezeAt`), sampel sintetis tiap 10 dtk pada detik :05 (meniru `collection_offset`).

| Berkas | Cakupan | Jumlah tes |
|---|---|---|
| `tests/Feature/InfrastructureTest.php` | DB tes MySQL, migrasi, UTC, token palsu, blokir HTTP, 8 aturan dari config | 6 |
| `tests/Feature/Alerting/StateMachineTest.php` | NORMAL→PENDING→FIRING→RESOLVED, durasi tahan tepat 60 vs 59 dtk, hysteresis, for=0, operator `<` per volume, layanan `!=`/`==`, REPEAT cooldown 600 dtk, heartbeat 30 dtk, data terlambat, aturan/sumber nonaktif, isi pesan WIB | 25 |
| `tests/Feature/Alerting/RegressionTest.php` | bug #2, #6 (halaman & tombol reset), #8, #10, #11; ubah ambang tidak me-reset state; perhitungan `alerts:latency` (A/B/C/D, sistem = total − tahan) dan pengecualian REPEAT/gagal/kondisi awal | 9 |
| `tests/Feature/Ingest/IngestTest.php` | 401/403, hash token, pemetaan Telegraf, idempoten, last_seen_at, validasi stempel waktu, 422, IP pengirim jaringan, IP server untuk loopback (+ offline dipertahankan, DHCP berubah, 127.0.0.1 tidak disimpan), ESP32 (rentang DHT22, NTP), rate limit per sumber (bug #12) & per IP sebelum auth | 23 |
| `tests/Feature/Notifications/TelegramJobTest.php` | sukses + message_id, 429 → release(retry_after), 4xx → gagal permanen, 5xx → diulang, penyamaran token, failed(), anti-kirim-ganda, config kosong, antrean/backoff/30 mnt, batas 4096 karakter | 11 |
| `tests/Feature/Notifications/EndToEndTest.php` | API Telegraf → `alerts:evaluate` → job → Telegram (dipalsukan), FIRING & RESOLVED terkirim | 1 |
| `tests/Feature/Dashboard/RulesPageTest.php` | halaman Aturan tanpa kontrol polling, simpan & label, 13 kasus validasi ditolak (termasuk uji 7e 90/95 dan durasi 5), 5 batas sah, ambang layanan tak bisa diubah, id tak dikenal, sakelar nonaktif, reset | 24 |
| `tests/Feature/Dashboard/DashboardTest.php` | halaman Ringkasan/Detail, JSON `/data/summary` & `/data/servers/{name}`, 404 | 5 |
| `tests/Unit/ConditionsTest.php` | operator `>` `<` `!=` `==` (batas tidak termasuk, toleransi float), operator tak dikenal, format angka label | 14 |
| `tests/Unit/ServerAddressTest.php` | penguraian alamat lokal socket: IPv4 diterima; loopback, `0.0.0.0`, IPv6, bukan-IP → null | 6 |
| `tests/Feature/Dashboard/LocalAccessTest.php` | dashboard hanya dari 127.0.0.1/::1, 7 halaman/aksi ditolak dari LAN (403), aturan tidak berubah saat ditolak, X-Forwarded-For palsu, DASHBOARD_ALLOWED_IPS (CIDR), API ESP32 tetap diterima dari LAN | 13 |
| `tests/Feature/EnvironmentFeatureTest.php` | saklar fitur lingkungan: saat `false` endpoint ESP32 → 404 sebelum cek token (last_seen_at tidak berubah), Telegraf tetap diterima, aturan ESP32 tidak dievaluasi walau `is_enabled = 1` (aturan host tetap FIRING), state ESP32 FIRING yang tertinggal tidak memicu REPEAT, `/data/summary` tanpa room/alert/aturan nonaktif ESP32, panel Ruang server & aturan ESP32 tidak dirender, PUT aturan ESP32 tersembunyi ditolak tanpa menyimpan apa pun, form berisi aturan host saja tersimpan, reset tidak menyentuh aturan ESP32; saat `true` semua tampil dan ESP32 diterima | 12 |
| `tests/Feature/Dashboard/HealthTest.php` | panel Kesehatan sistem: evaluator belum pernah jalan / sehat ≤ 60 dtk / bermasalah > 60 dtk, antrean kosong / tertua 59 vs 60 dtk, notifikasi gagal 24 jam (yang > 24 jam tidak dihitung, token disamarkan), status keseluruhan, JSON `/data/summary` + panel dirender | 6 |
| `tests/Feature/Alerting/SimulationTest.php` | K3 `alerts:simulate`: skenario lonjakan, kedip, osilasi (S1 vs S2 = efek histeresis), berkelanjutan (tunda = 60 dtk, REPEAT 2×), keluaran perintah campuran, replay data nyata tanpa menulis DB, penolakan heartbeat & skenario tak dikenal | 7 |
| **Total** | | **168 tes, 939 asersi** (114 per 21 Okt sore + 13 akses lokal + 12 saklar fitur + 10 IP server + 6 perbaikan tinjauan + 6 kesehatan + 7 simulasi) |

- **Hasil di H1 (21 Okt 2026, 21:42 WIB): `php artisan test` → 114 passed (757 assertions), durasi 36,65 dtk** (Windows, PHP 8.4.25 ZTS, MySQL DBngin, database `servermonitoring_test`). Tes terlama: tes pertama Feature 6,71 dtk (termasuk migrasi awal `RefreshDatabase`), blokir HTTP 3,75 dtk, uji akhir-ke-akhir 1,25 dtk; sisanya < 1 dtk.
- Penambahan 21 Okt malam (`LocalAccessTest`, 13 tes): lolos di replika, total 127 tes / 774 asersi.
- Penambahan 22 Okt (`EnvironmentFeatureTest`, 12 tes): lolos di replika → 139 lolos, 827 asersi, ±7,6 dtk.
- **Hasil di H1 (22 Okt 2026, ±01:05 WIB): `php artisan test` → 139 passed (827 assertions), durasi 13,84 dtk** (dengan `LocalAccessTest` dan `EnvironmentFeatureTest`).
- Penambahan 24 Okt malam (B1 panel kesehatan 6 tes, K3 simulasi 7 tes): lolos di replika → **168 lolos, 939 asersi, ±8,7 dtk**; menunggu H1.
- Penambahan 24 Okt (perbaikan tinjauan #1/#2: 4 tes ingest, 1 tes job `t_started`, 1 tes latensi notifikasi lama; 1 tes latensi diperbarui ke definisi baru): lolos di replika → **155 lolos, 870 asersi, ±6,5 dtk**; menunggu H1. Mutation check: mengembalikan `touch()` tanpa syarat → 2 tes gagal; menghapus pengisian `t_started` → 2 tes gagal.
- Penambahan 22 Okt (IP server: 5 tes ingest baru menggantikan 1 tes lama + 6 tes unit): lolos di replika → **149 lolos, 843 asersi, ±8 dtk**; menunggu H1. Deteksi IP server dipalsukan di `tests/TestCase.php` (`fakeServerIp()`, default null) agar tes tidak bergantung pada jaringan mesin tes.
- Semua tes lain dijalankan dengan fitur lingkungan **aktif** (`tests/TestCase.php` mengisi `monitoring.features.environment = true`), sehingga hasil tes tidak bergantung pada isi `.env` H1.
- Hasil di lingkungan replika (21 Okt 2026): PHP 8.4.26, MySQL 8.0.46, Laravel 13.38.0, dependensi dari `composer.lock` proyek → **114 lolos, 0 gagal, ±7 dtk**; lolos juga dalam urutan acak (`--order-by=random`, 2×).
- Bukti tes regresi benar-benar mendeteksi bug (mutation check manual): mengembalikan kode bug #6 → 2 tes gagal; bug #10 → 1 tes gagal (`'10:00:45'` ≠ `'10:00:44'`); menghapus cast `last_sample_at` (bug #2) → 2 tes gagal; tanpa perbaikan bug #12 → tes rate limit gagal.
- Catatan metodologi untuk Bab 4: pengujian otomatis melengkapi (bukan menggantikan) pengujian black-box manual dan pengukuran latensi; tes membuktikan logika pada kasus batas yang sulit dibuat manual (429, 4xx, data terlambat, durasi 59 vs 60 dtk). Tes memakai data sintetis dan Telegram palsu, sehingga TIDAK mengukur latensi nyata.


## 18. Firmware ESP32 + DHT22 + OLED [V sebagian — jalan di perangkat (DHT22 saja), 21–22 Okt 2026]
Lokasi: `firmware/esp32-dht22/esp32-dht22.ino` — **satu berkas** (permintaan pengguna 21 Okt; sebelumnya dipecah ke beberapa `.cpp/.h`). Panduan: `firmware/esp32-dht22/README.md`.
Susunan berkas: (1) RAHASIA — WiFi, `SERVER_URL`, `SOURCE_TOKEN`; dipakai dari `secrets.h` bila berkas itu ada (`__has_include`, `secrets.h` di-gitignore), (2) PENGATURAN, (3) tipe data & global (semua tipe sebelum fungsi pertama agar prototipe otomatis Arduino IDE benar; tanpa template), (4)–(8) status bersama, antrean, sensor, WiFi/NTP, kirim HTTP, (9) OLED (task FreeRTOS core 0), (10) `setup()`/`loop()`. Status bersama dilindungi critical section `portMUX`.

- **Status perangkat (21 Okt malam): hanya DHT22 (`USE_OLED 0`) karena belum ada breadboard; DHT22 disambung langsung dengan kabel jumper female-to-female. OLED akan dipasang kemudian (ubah `USE_OLED 1`). Kode OLED tetap ada dan sudah lolos kompilasi.** Varian tanpa OLED: program 1.052.076 byte (80%), RAM 50.688 byte (15%).
- Perangkat: ESP32 Dev Module (ESP32-WROOM-32); DHT22 modul 3 pin → GPIO4 (3V3); OLED 0.96" I2C 4 pin → SDA GPIO21, SCL GPIO22 (3V3), alamat 0x3C, driver SSD1306/SSD1315 (opsi SH1106 lewat `OLED_DRIVER_SH1106`).
- Library: core esp32 (Espressif) 3.3.12, DHT sensor library 1.4.7, Adafruit Unified Sensor 1.1.15, U8g2 2.37.1.
- Alur tiap 30 dtk: DHT22 dibaca 3× berjarak 2,5 dtk → median (pembulatan 0,1) → bila jam NTP tersinkron (UTC), masuk antrean RAM (ring buffer 20) → dikirim dari yang tertua `POST /api/ingest/esp32` `{temperature, humidity, ts}` + `Authorization: Bearer`; maks 10 kirim per siklus; berhenti pada kegagalan pertama agar urutan terjaga.
- Respons: 200 → hapus dari antrean; 422 → buang (ditolak permanen); 401/403 → tetap diantre + status "TOKEN?"; lainnya (429, 5xx, timeout −1) → coba lagi siklus berikut. Timeout: connect 3 dtk, respons 5 dtk.
- WiFi: auto-reconnect + `WiFi.reconnect()` tiap 30 dtk saat putus; restart ESP32 bila putus > 5 menit. NTP: `id.pool.ntp.org`, `pool.ntp.org`; dianggap sinkron bila epoch > 1.700.000.000.
- OLED: halaman SUHU (animasi termometer, skala 15–35 °C) → KELEMBABAN (animasi tetes) → STATUS (WiFi, server, jam WIB/antrean), masing-masing 5 dtk; halaman "DHT22 ERROR" menggantikan SUHU/KELEMBABAN saat sensor gagal. Pita atas 16 px: label + ikon WiFi, titik server (penuh = terkirim), jumlah antrean/`!`. Font: Terminus bold 8×16 (label), Helvetica Bold 24 px (angka, ±3,7 mm di panel 0,96"). Anti burn-in: geser 0–2 px tiap siklus, kontras 40/255. OLED tidak menampilkan status alarm (sumber kebenaran tunggal = server). OLED dicek lewat I2C saat boot; bila tidak ada, firmware tetap jalan.
- Verifikasi (21 Okt 2026, lingkungan replika, memakai ctags Arduino 5.8-arduino11 untuk prototipe `.ino`): `arduino-cli compile --fqbn esp32:esp32:esp32 --warnings all` → tanpa error/peringatan dari kode proyek; program 1.068.364 byte (81% dari 1.310.720), RAM global 51.912 byte (15%). Varian `USE_OLED 0` (80%), `OLED_DRIVER_SH1106 1`, dan jalur `secrets.h` juga lolos (URL dari `secrets.h` terbukti terpakai di biner).
- Simulasi tampilan: fungsi gambar OLED yang sama (isi bagian 9 identik dengan versi uji simulasi) dijalankan di PC memakai buffer SSD1306 asli U8g2 → `docs/gambar/oled-simulasi-*.png/gif` (bukan foto perangkat; foto asli diambil setelah uji perangkat).
- **Uji perangkat pertama (21 Okt 2026, 23:59 WIB)** [V]: ESP32 Dev Module + DHT22 (tanpa OLED), WiFi 2,4 GHz, server `http://192.168.1.36:8000` (`serve --host=0.0.0.0`). Serial: `[HTTP] 200 {"temperature":28.9,"humidity":100.0,"ts":1792601960} {"stored":2}`; `ts` = 23:59:20 WIB, cocok dengan jam Serial Monitor (23:59:20.579) → NTP & stempel waktu UTC benar. Setelah reset ESP32, siklus pertama mencatat `[NTP] jam belum sinkron` (sesuai rancangan: data siklus itu tidak dikirim).
- **Temuan sensor**: kelembaban terbaca tetap **100,0 %RH** (suhu 28,8–28,9 °C wajar). Rentang DHT22 0–100 %RH; nilai tepat 100,0 yang menetap mengindikasikan sensor jenuh (embun) atau sensor bermasalah/tiruan — perlu dicek (warna modul putih = DHT22/AM2302, biru = DHT11), dikeringkan, dan dibandingkan dengan higrometer. Data 100 % akan memicu `humidity_high` (> 60 % selama 60 dtk) — perilaku sistem benar terhadap masukan, tetapi masukannya dicurigai salah.
- **Isolasi penyebab (22 Okt 2026, 00:17 WIB)** [V]: modul berwarna putih (DHT22/AM2302); ditiup tidak mengubah nilai; contoh resmi library *DHT sensor library → DHTtester* (DHTPIN 4, DHTTYPE DHT22, 9600 baud) juga membaca `Humidity: 100.00%  Temperature: 28.40°C` terus-menerus → kelainan berasal dari **perangkat keras sensor (elemen kelembaban)**, bukan firmware/server. Tindak lanjut: keringkan beberapa jam; bila tetap 100 %, ganti DHT22. Data kelembaban sebelum sensor diganti TIDAK dipakai sebagai hasil penelitian.
- Diagnosis koneksi: IP WiFi H1 = 192.168.1.36 (adapter `Ethernet 2` 192.168.56.1 = VirtualBox Host-Only, bukan untuk ESP32); `curl` tanpa token dari IP WiFi → 401 dengan `X-RateLimit-Limit: 300` (urutan throttle IP → auth terbukti di server nyata). Aturan firewall dibuat dari PowerShell Administrator.
- Temuan keamanan kecil: header `X-Powered-By: PHP/8.4.25` (CWE-200; perbaikan `expose_php = Off`), `Access-Control-Allow-Origin: *` bawaan Laravel untuk `api/*` (risiko rendah karena autentikasi Bearer, bukan cookie).
- Belum diuji: tampilan OLED di panel asli, perilaku antrean saat WiFi/server diputus, heartbeat ESP32, alarm suhu/kelembaban sampai Telegram.

## 19. Saklar fitur lingkungan ruang server (22 Okt 2026) [V — replika; endpoint 404 terverifikasi di H1]
Latar: fokus skripsi pindah ke Judul 1 (resource server saja) setelah DHT22 rusak (§18); opsi kembali ke Judul 2 tetap terbuka sampai sebelum naskah proposal diserahkan. Desain dipilih pengguna: **opsi A (saklar `.env`)**; ditolak: B (hanya mematikan aturan — panel Ruang server dan endpoint tetap terbuka), C (hapus kode di `main` + branch `judul-2` — branch makin berbeda, kembali = merge sulit).

| Bagian | `MONITORING_ENVIRONMENT_ENABLED=false` (Judul 1, default) | `true` (Judul 2) |
|---|---|---|
| `POST /api/ingest/esp32` | 404 JSON; middleware `feature.environment` dipasang SEBELUM `source.auth:esp32` → token tidak diperiksa, `last_seen_at` tidak berubah | seperti biasa (200/401/403/422) |
| Mesin alarm (`AlertEngine`) | aturan & sumber bertipe `esp32` dilewati di level kode walaupun `is_enabled = 1` → tidak ada transisi, notifikasi, atau REPEAT | dievaluasi |
| Dashboard Ringkasan | panel "Ruang server" dan tombol pintasnya tidak dirender (Blade `@if`); teks "Semua server normal"; JSON `/data/summary`: `room = null`, alert & daftar aturan nonaktif hanya dari host, `features.environment = false` | lengkap |
| Halaman Aturan | grup "Ruang server (ESP32)" dan `heartbeat_esp32` tidak ditampilkan; PUT dengan id aturan ESP32 → "Aturan tidak dikenal" (tidak ada yang disimpan); tombol reset hanya mengembalikan aturan host | lengkap |
| `source:create … esp32` | tetap bisa; menampilkan peringatan bahwa endpoint 404 sampai fitur aktif | normal |

Berkas: `app/Support/Features.php` (baru: `environment()`, `sourceTypes()`), `app/Http/Middleware/EnsureEnvironmentFeature.php` (baru), `config/monitoring.php` (`features.environment`), `bootstrap/app.php` (alias `feature.environment`), `routes/api.php`, `AlertEngine.php`, `SummaryBuilder.php`, `DashboardController.php`, `RuleController.php`, `AlertRuleSeeder::run(?array $sourceTypes = null)`, `CreateSource.php`, `dashboard/index.blade.php`, `rules/edit.blade.php`, `.env.example`, `tests/TestCase.php`, `tests/Feature/EnvironmentFeatureTest.php` (baru, 12 tes).

Verifikasi H1 (22 Okt 2026, 00:54 WIB) [V]: `curl -i -X POST http://127.0.0.1:8000/api/ingest/esp32` → `HTTP/1.1 404 Not Found`, exception dilempar dari `EnsureEnvironmentFeature.php` baris 19 (sebelum `source.auth`). Dashboard tanpa panel Ruang server dan halaman Aturan tanpa grup ESP32 — dikonfirmasi pengguna di H1 (22 Okt, 01:00 WIB) [V]. `php artisan test` di H1: 139 passed (827 assertions), 13,84 dtk [V].

Keterbatasan yang disadari: saklar tidak mengubah isi database. State aturan ESP32 yang sedang FIRING saat saklar dimatikan akan tetap FIRING di database (tidak ditampilkan dan tidak memicu notifikasi selama saklar `false`). Karena itu prosedur pindah (PERINTAH §3c) mewajibkan aturan ESP32 dimatikan lewat halaman Aturan SEBELUM saklar diubah ke `false` (halaman Aturan me-reset state ke NORMAL), dan diaktifkan lagi lewat halaman Aturan SETELAH saklar `true` (menggeser `last_sample_at`, bug #6).

## 20. Panel "Kesehatan sistem" — pemantauan diri (24 Okt 2026) [V — replika; menunggu H1]
Masalah (tinjauan #4): bila `schedule:work` atau `queue:work` mati, dashboard tampak normal tetapi tidak ada alarm yang dievaluasi/dikirim.
- Lokasi: kotak Ringkasan dashboard, satu baris "Kesehatan sistem" berisi 3 indikator; ikut polling `/data/summary` (kunci `health`); umur bertambah di sisi browser antar-polling.
- **Evaluator**: `AlertEngine::evaluate()` menulis `Cache::forever('monitoring.evaluator.last_run_at', now)` setiap selesai (cache store sesuai `.env`, di H1 `database`). Sehat bila umur ≤ **60 dtk** (keputusan pengguna 24 Okt; usulan awal 30 dtk). Belum pernah jalan → bermasalah.
- **Antrean**: notifikasi `status = queued` (termasuk yang menunggu jeda retry): jumlah + umur tertua (`t_queued`). Sehat bila kosong atau tertua < **60 dtk**. Pesan error terakhir (disamarkan) tampil sebagai tooltip.
- **Gagal 24 jam**: jumlah `status = failed` dengan `t_queued` dalam 24 jam + error terakhir (token disamarkan, maks 160 karakter).
- Batas di `config/monitoring.php` → `health` (`evaluator_stale_seconds` 60, `queue_stale_seconds` 60, `failed_window_hours` 24).
- Berkas: `app/Services/Dashboard/HealthBuilder.php` (baru), `AlertEngine.php`, `SummaryBuilder.php`, `config/monitoring.php`, `dashboard/index.blade.php`, `tests/Feature/Dashboard/HealthTest.php` (baru, 6 tes).
- Batasan: hanya tampil di dashboard; evaluator/worker yang mati tidak bisa mengirim Telegram tentang dirinya. Pemulihan otomatis → Tahap 9.

## 21. K3 — simulasi aturan naif vs mesin alarm sistem (`alerts:simulate`, 24 Okt 2026) [V — replika; menunggu H1]
Tujuan (kebaruan K3): mengukur pengurangan notifikasi transien/berulang (flapping) beserta harganya (tunda deteksi), pada data yang sama. Hanya membaca data; tidak menulis DB, tidak mengirim Telegram.
- Berkas: `app/Services/Alerting/Simulation/Simulator.php`, `Scenarios.php`, `app/Console/Commands/SimulateAlerts.php` (semua baru), `tests/Feature/Alerting/SimulationTest.php` (7 tes). Konfigurasi S1–S3 memakai `StateMachine` yang SAMA dengan sistem (di memori).
- Konfigurasi: **N1** naif per sampel (pesan tiap sampel di atas ambang); **N2** naif persilangan (FIRING saat melewati ambang, RESOLVED saat kembali); **S1** + durasi tahan (ambang pulih = ambang pemicu); **S2** + histeresis (ambang pulih aturan); **S3** sistem lengkap (+ REPEAT tiap cooldown, diukur dengan waktu sampel).
- "Kejadian" = rangkaian sampel berturut-turut melewati ambang (putus bila jeda > 30 dtk); **berkelanjutan** bila durasi (sampel terakhir − pertama) ≥ durasi tahan, selain itu **transien**. Ini definisi operasional, bukan label "masalah nyata" — karena itu istilah yang dipakai "notifikasi transien/berulang", bukan "alarm palsu". Kebenaran dasar independen dari skenario sintetis.
- Skenario sintetis (relatif terhadap ambang aturan, sampel tiap 10 dtk): `lonjakan` (6 × 30 dtk), `kedip` (bergantian buruk/tepat di bawah ambang 10 mnt), `osilasi` (6 siklus buruk 60 dtk + abu-abu 10 dtk di antara ambang pulih dan pemicu), `berkelanjutan` (5, 5, 25 mnt), `campuran`.
- Keluaran per konfigurasi: notifikasi total, pemicu (ALERT/FIRING), RESOLVED, REPEAT, kejadian berkelanjutan terdeteksi (alarm aktif di dalam kejadian), pemicu dari kejadian transien, tunda deteksi rata-rata (dari awal kejadian berkelanjutan ke pemicu baru), pengurangan vs N1 dan vs N2. `--csv` → `storage/app/simulate-*.csv`.
- Contoh keluaran skenario `campuran` (data SINTETIS, contoh alat — BUKAN hasil penelitian), aturan cpu_high 85/80/60 dtk/600 dtk, 906 sampel, 9 berkelanjutan + 36 transien:
  | Konf. | Notif total | Pemicu | RESOLVED | REPEAT | Berkelanjutan terdeteksi | Pemicu dari transien | Tunda (dtk) | vs N1 | vs N2 |
  |---|---|---|---|---|---|---|---|---|---|
  | N1 | 300 | 300 | 0 | 0 | 9/9 | 48 | 0,0 | 0,0% | −233,3% |
  | N2 | 90 | 45 | 45 | 0 | 9/9 | 36 | 0,0 | 70,0% | 0,0% |
  | S1 | 18 | 9 | 9 | 0 | 9/9 | 0 | 60,0 | 94,0% | 80,0% |
  | S2 | 8 | 4 | 4 | 0 | 9/9 | 0 | 60,0 | 97,3% | 91,1% |
  | S3 | 10 | 4 | 4 | 2 | 9/9 | 0 | 60,0 | 96,7% | 88,9% |
- Catatan tafsir: S3 > S2 karena REPEAT adalah pengingat yang disengaja, bukan notifikasi berlebih; harga S1–S3 adalah tunda deteksi = durasi tahan (60 dtk) dan kejadian < 60 dtk tidak dilaporkan (disengaja).
- Batasan: replay data nyata memakai urutan sampel yang tersimpan; jeda data (host mati) diperlakukan seperti evaluator (durasi tahan dihitung dengan waktu data). REPEAT disimulasikan pada waktu sampel, bukan tiap 10 dtk jam server.

