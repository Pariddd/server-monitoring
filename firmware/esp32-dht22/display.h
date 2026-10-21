// Tampilan OLED 0.96" 128x64: halaman SUHU → KELEMBABAN → STATUS bergantian.
// Berjalan sebagai task FreeRTOS terpisah sehingga animasi tidak tersendat saat
// task utama menunggu respons server, dan pengiriman data tidak terganggu animasi.
#pragma once

void displayStart();  // panggil sekali di setup(); tidak melakukan apa pun bila USE_OLED = 0
