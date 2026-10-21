// Status bersama antara task utama (sensor + jaringan) dan task tampilan OLED.
// Diakses lewat snapshot di dalam critical section agar aman antar-core.
#pragma once
#include <Arduino.h>

struct SharedState {
  bool     hasReading   = false;  // sudah pernah ada pembacaan valid
  float    temperature  = NAN;    // °C, 1 desimal
  float    humidity     = NAN;    // %RH, 1 desimal
  bool     sensorError  = false;  // pembacaan terakhir gagal seluruhnya
  bool     wifiOk       = false;
  bool     serverOk     = false;  // pengiriman terakhir diterima server (HTTP 200)
  bool     authError    = false;  // server menolak token (401/403)
  bool     ntpOk        = false;  // jam sudah tersinkron NTP
  uint8_t  queueLen     = 0;      // jumlah pembacaan yang belum terkirim
};

extern SharedState g_state;
extern portMUX_TYPE g_stateMux;

inline SharedState stateSnapshot() {
  portENTER_CRITICAL(&g_stateMux);
  SharedState copy = g_state;
  portEXIT_CRITICAL(&g_stateMux);
  return copy;
}

// Ubah state di dalam critical section. Contoh: stateUpdate([](SharedState &s){ s.wifiOk = true; });
template <typename F>
inline void stateUpdate(F fn) {
  portENTER_CRITICAL(&g_stateMux);
  fn(g_state);
  portEXIT_CRITICAL(&g_stateMux);
}
