# Kebaruan Judul 1 & Judul 2 dan Rancangan Pengujian Judul 2

Disusun 22 Oktober 2026, diperluas 23 Oktober 2026 (bagian D: kebaruan Judul 2; bagian E: ringkasan) dari jurnal di Project. Label: **[Jurnal]** = isi file (nama file + halaman); **[Analisis]** = pendapat Claude, bukan isi jurnal.

---

## A. Celah (gap) di jurnal Project yang relevan dengan Judul 1

| # | [Jurnal] Apa yang dilakukan penelitian terdahulu | Sumber | [Analisis] Celahnya |
|---|---|---|---|
| G1 | Pengujian alarm hanya lulus/gagal: notifikasi "terkirim" pada keadaan normal dan keadaan melebihi ambang | `monitoring server telegraf/1546-Article Text-8408-1-10-20240921.pdf` hlm 1464 (Tabel 2) | Tidak ada ukuran waktu, tidak ada uji alarm palsu |
| G1 | Uji notifikasi per trigger hanya status "dapat ditampilkan"; alert "Apache2 is Down" tidak bekerja | `monitoring server telegraf/4550-Article Text-11769-1-10-20220306.pdf` hlm 185 (Tabel 6) | Sama: fungsional saja, dan ada trigger yang gagal |
| G2 | Waktu deteksi dan waktu pengiriman alert diukur; rata-rata 12,27 menit (eksperimen) vs 17,73 menit (kontrol), uji t independen | `8024-Article Text-18272-1-10-20251017.pdf` hlm 1154–1156 | Resolusi menit, skema "2 (dua) kali pengujian" (hlm 1155), tidak diuraikan per tahap (ingest, evaluasi, antrean, kirim), tanpa persentil |
| G2 | Waktu pengiriman Telegram rata-rata 2,6 dtk dan 6,4 dtk (dua provider) | `5135-20533-1-PB.pdf` hlm 67 (abstrak), hlm 74–75 | Konteks IoT suhu ruang, bukan resource server; hanya rata-rata |
| G3 | Perangkat mati terdeteksi bila 1 jam tidak ada pembaruan data | `JurnalFarhan.pdf` hlm 22 | Resolusi kasar (1 jam), kinerjanya tidak diukur; konteks IoT |
| G4 | Pemicu alarm berupa ambang sesaat: CPU ≥ 80%, RAM bebas ≤ 100 MB, PID tidak ditemukan | `monitoring server telegraf/4550-Article Text-11769-1-10-20220306.pdf` bagian 3.1, Tabel 1 | Tidak disebut durasi tahan, ambang pulih (histeresis), atau pembatas pengulangan → rawan alarm berkedip (flapping) |
| G4 | FIRING dan RESOLVED dikirim oleh Alertmanager (fitur bawaan) | `8024-Article Text-18272-1-10-20251017.pdf` hlm 1155 | Mekanismenya dipakai, tidak dirancang atau dievaluasi |
| G5 | Platform siap pakai: Prometheus + Grafana + Node Exporter (Ubuntu Server) | `monitoring server telegraf/1546-...pdf` hlm 1457–1459; `8024-...pdf` hlm 1151, 1154 | Logika alarm milik platform, bukan kontribusi peneliti |
| G5 | Zabbix (+ Grafana), notifikasi email/Telegram | `monitoring server telegraf/JAKAKOM322-329.pdf` hlm 323, 327; `eni_irfiani04FNI85-95.pdf` hlm 86 | Sama |
| G6 | Host yang dipantau berbasis Linux (Ubuntu Server; container Debian/Ubuntu di Proxmox) | `1546-...pdf` hlm 1458; `eni_irfiani04FNI85-95.pdf` hlm 86; `4550-...pdf` hlm 185 | Di jurnal Project tidak ditemukan pemantauan host Windows (performance counter, status layanan Windows) |
| G7 | Saran: perluas ke tingkat layanan (web server, database, cron job); perbanyak sampel dan variasi beban | `8024-...pdf` hlm 1156 | Peluang: status layanan Windows + ≥ 20 percobaan per skenario |
| G8 | Saran: teliti aspek keamanan dan keandalan sistem monitoring | `eni_irfiani04FNI85-95.pdf` hlm 95 | Peluang: token per host, rate limit, dashboard lokal, pengiriman ulang (retry, HTTP 429) |

**Ancaman kebaruan (belum ada file aslinya):** Prabowo dkk. (2025), *Sistem Monitoring Server dan Website CV. Technos Studio Menggunakan Laravel Scheduller dan Telegram Bot*, Jurnal Pustaka Data 5(1), 205–213 — dikutip di `8024-...pdf` hlm 1157. Juga Juantoro & Ratama (2022), notifikasi monitoring server bot Telegram dengan cronjob — `8024-...pdf` hlm 1157. Isinya belum diketahui; wajib dibaca sebelum menulis Bab I. Kata kunci: "Prabowo 2025 Laravel Scheduller Telegram Bot Technos Studio", "Juantoro Ratama 2022 cronjob bot Telegram OKTAL".

---

## B. Usulan kebaruan Judul 1 [Analisis]

### K1 (inti) — Mesin alarm berstatus yang dirancang sendiri dan dievaluasi kuantitatif
State machine NORMAL → PENDING → FIRING → RESOLVED dengan durasi tahan berbasis waktu data, ambang pulih (histeresis), cooldown, dan heartbeat agen 30 dtk — dibangun di Laravel, bukan fitur platform. Menjawab G4, G5, G3. **Sudah terbangun dan teruji otomatis.**

### K2 — Latensi notifikasi end-to-end terdekomposisi per tahap
Latensi = ingest (A) + evaluasi (B) + antrean (C) + kirim (D), ≥ 20 percobaan per skenario, dilaporkan rata-rata, median, p95, maksimum terhadap target yang ditetapkan di awal. Menjawab G2 (8024: resolusi menit, 2 kali uji; 5135: rata-rata saja). **Alat ukur sudah ada (`alerts:latency`).**

### K3 — Pengurangan alarm palsu (flapping) yang terukur — usulan TAMBAHAN
Bandingkan, pada data rekaman yang sama, jumlah notifikasi aturan naif (ambang sesaat, seperti 4550 Tabel 1) vs aturan sistem (durasi tahan + histeresis + cooldown). Hasil: jumlah notifikasi dan persentase pengurangan. Menjawab G1 dan G4 dengan angka. **SUDAH DIBUAT 24 Okt 2026: `php artisan alerts:simulate` (konfigurasi N1, N2, S1, S2, S3; skenario sintetis + replay data nyata) — lihat CATATAN §21.**

### K4 — Pemantauan host Windows (performance counter + status layanan Windows)
Menjawab G6 dan G7. **Sudah terbangun.** Hati-hati: klaim "belum ada" hanya berlaku untuk jurnal di Project.

### K5 — Keandalan dan keamanan jalur data/notifikasi
Token per host (hash SHA-256), rate limit per IP dan per sumber, dashboard hanya lokal, antrean dengan retry/backoff dan penanganan HTTP 429, semantik at-least-once. Menjawab G8. **Sudah terbangun; perlu skenario uji keandalan (Telegram/jaringan putus).**

Yang TIDAK disarankan: menambah machine learning/prediksi — memperluas lingkup tanpa data latih yang memadai.

### Contoh rumusan masalah (draf)
1. Bagaimana merancang mesin alarm berstatus (durasi tahan, histeresis, cooldown, heartbeat) pada sistem monitoring resource server berbasis Laravel dan Telegraf?
2. Berapa latensi notifikasi Telegram sistem, diuraikan per tahap, terhadap target yang ditetapkan?
3. Seberapa besar pengurangan notifikasi palsu dibanding aturan ambang sesaat? (bila K3 disetujui)

---

## C. Rancangan pengujian Judul 2 (dengan ESP32 + DHT22)

| No | Pengujian | [Jurnal] Pembanding | [Analisis] Cara dan ukuran |
|---|---|---|---|
| P1 | Akurasi sensor vs alat pembanding | `14_PUBLIKASI_1757_122-129.pdf` hlm 128: 1 jam, selisih suhu 0,3 °C, kelembaban 5,1 %RH vs HTC-2; `5135-20533-1-PB.pdf` hlm 67: %error suhu 6,22, kelembaban 9,56 vs HTC-1; `3120-Article Text-10978-1-10-20260704.pdf` hlm 160; `monitoring server telegraf/22_02_01_Utomo.pdf` hlm 181: error 2,35% | Sensor dan termohigrometer digital berdampingan; ≥ 1 jam setelah pemanasan; pasangan bacaan tiap 1 menit; MAE, bias (rata-rata selisih bertanda), % error; spesifikasi alat pembanding dicantumkan (pedoman hlm 6) |
| P2 | Fungsional alarm suhu/kelembaban | `2699-Article Text-11271-1-10-20260725.pdf` hlm 39 (Tabel 2: ≥ 30 °C terkirim, normal tidak) | Sumber panas/lembab terkendali; cek PENDING → FIRING setelah 60 dtk, RESOLVED di bawah ambang pulih, tanpa notifikasi saat normal |
| P3 | Latensi notifikasi lingkungan | `5135-20533-1-PB.pdf` hlm 74–75 (rata-rata 2,6 / 6,4 dtk) | Sama dengan K2: A/B/C/D, ≥ 20 percobaan, median & p95 |
| P4 | Deteksi perangkat mati (heartbeat ESP32) | `JurnalFarhan.pdf` hlm 22 (1 jam) | Cabut daya / matikan WiFi; waktu sampai FIRING (batas 90 dtk); ≥ 20 percobaan |
| P5 | Ketahanan putus server/jaringan | `14_PUBLIKASI_1757_122-129.pdf` hlm 124 (uji keandalan berjalan terus-menerus) | Matikan server 5 menit; data dari antrean ESP32 (maks 20) terkirim saat pulih; hitung % data yang sampai |
| P6 | Kelengkapan data jangka panjang | — | 24 jam: data diterima vs seharusnya (2.880 per 24 jam pada interval 30 dtk) |
| P7 | Validasi masukan | — | Sensor gagal baca → tidak mengirim; nilai di luar rentang → 422 |

Catatan dari kasus 22 Okt 2026: DHT22 pertama rusak (kelembaban konstan 100%). P1 wajib dilakukan sebelum data lingkungan dipakai; bila kembali ke Judul 2, beli DHT22 baru + termohigrometer pembanding.

---

## D. Kebaruan khusus Judul 2 (ditambahkan 23 Okt 2026)

### Celah di jurnal Project (lingkungan ruang server)
| # | [Jurnal] Temuan | Sumber | [Analisis] Celah |
|---|---|---|---|
| H1 | Sistem IoT hanya memantau suhu/kelembaban ruang (ESP32/ESP8266/Arduino + DHT) | `14_PUBLIKASI_1757_122-129.pdf` hlm 124; `2699-...pdf` hlm 37–38; `iyamin4...Budianto...pdf` hlm 24; `5135-20533-1-PB.pdf` hlm 67 | Tidak digabung dengan resource server. Sebaliknya jurnal server (1546, 8024, JAKAKOM) tidak memantau lingkungan ruang. Ancaman: Lambacing dkk. (2020) Raspberry Pi + Zabbix suhu data center — hanya dikutip di `eni_irfiani04FNI85-95.pdf` hlm 95, file asli belum ada |
| H2 | Laravel sudah dipakai untuk antarmuka monitoring suhu ruang server | `iyamin4.Sainstech-353-Budianto-...pdf` hlm 24, 30 | "Laravel + IoT" saja BUKAN kebaruan |
| H3 | Masalah: "tidak adanya mekanisme peringatan dini ketika terjadi kenaikan suhu"; data menunjukkan suhu naik rata-rata ±0,9 °C/menit pada fase awal, ambang 30 °C | `2699-...pdf` hlm 37, 41 | Alarm hanya ambang tetap; laju kenaikan diukur tetapi tidak dipakai sebagai pemicu |
| H4 | Kekurangan: "belum tersedia fitur deteksi lonjakan ekstrem suhu/kelembaban dalam waktu singkat"; deviasi kelembaban ±5% | `14_PUBLIKASI_1757_122-129.pdf` hlm 128 | Celah dinyatakan langsung oleh penelitinya |
| H5 | Notifikasi Telegram berkala tiap 5 menit + peringatan saat ambang terlampaui (27 °C / 60%) | `14_PUBLIKASI_1757_122-129.pdf` hlm 126 | Tanpa durasi tahan, histeresis, RESOLVED |
| H6 | Saran: kalibrasi sensor secara berkala | `3120-...pdf` hlm 160 | Kerusakan/kemacetan sensor tidak dideteksi otomatis |
| H7 | Data tidak terkumpul bila ping > 300 ms | `monitoring server telegraf/22_02_01_Utomo.pdf` hlm 181 | Tidak ada penyangga data saat jaringan buruk |
| H8 | Perangkat mati terdeteksi bila 1 jam tanpa data | `JurnalFarhan.pdf` hlm 22 | Resolusi kasar |

### Usulan [Analisis]
| Kode | Kebaruan | Menjawab | Status |
|---|---|---|---|
| L1 | Satu mesin alarm & satu dashboard untuk dua jenis sumber (agen Telegraf + perangkat IoT), aturan seragam (durasi tahan, histeresis, cooldown, heartbeat) | H1, H5 | Sudah terbangun (saklar `true`) |
| L2 | **Alarm laju kenaikan suhu** (mis. naik ≥ X °C dalam N menit, kasus AC mati) sebagai peringatan dini sebelum ambang tetap tercapai; ukuran: *lead time* (berapa menit lebih awal dari alarm ambang) | H3, H4 | BELUM — perlu jenis aturan baru `rate` |
| L3 | **Deteksi sensor tidak valid** (nilai macet/flatline, mis. kelembaban 100% konstan selama N menit) → alarm "sensor dicurigai rusak"; berasal dari kasus nyata DHT22 22 Okt 2026 | H6, H4 | BELUM — perlu jenis aturan baru |
| L4 | Heartbeat ESP32 90 dtk + penyangga 20 bacaan dengan stempel waktu asli (NTP) → kelengkapan data saat jaringan/server putus | H7, H8 | Sudah terbangun (firmware) |

## E. Ringkasan rekomendasi [Analisis]
| | Judul 1 | Judul 2 |
|---|---|---|
| Inti | K1 + K2 | K1 + K2 + L1 |
| Pembeda terukur | K3 (pengurangan alarm palsu) | L2 (lead time peringatan dini) dan/atau L3 |
| Pendukung | K4 (Windows), K5 (keandalan/keamanan) | K5, L4 |
| Pekerjaan baru | K3: perintah simulasi (replay) | L2/L3: jenis aturan baru + tes; P1 akurasi sensor (beli DHT22 + termohigrometer) |

