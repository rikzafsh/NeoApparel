# Neo Apparel

Aplikasi web toko pakaian terintegrasi (PHP + MySQL/MariaDB): **TPS** (transaksi), **OAS** (perkantoran), **KMS** (pengetahuan), **MIS** (analitik), **DSS** (pemilihan supplier), dan **Expert System** (sistem pakar) yang menghubungkan kelimanya.

## Instalasi cepat (XAMPP / Laragon)

1. Salin folder ini ke `htdocs/neo_apparel` dan jalankan Apache + MySQL/MariaDB. **Wajib PHP 8.1+.**
2. Impor dua berkas ini lewat phpMyAdmin (tab *Import*) atau CLI, **urut**:
   ```bash
   mysql -u root < sql/install_kms.sql
   mysql -u root < sql/install_main.sql
   ```
   Keduanya membuat database `db_neo_apparel` dan `db_neo_apparel_kms` beserta data demo.
3. Sesuaikan koneksi bila perlu: salin `config.local.example.php` menjadi `config.local.php` (default port **3307** seperti proyek asli; XAMPP biasanya **3306**).
4. Buka `http://localhost/neo_apparel/` lalu masuk memakai akun demo.

Tanpa Apache: `php -S localhost:8000` di folder proyek.

### Akun demo (kata sandi semua: `NeoApparel123!`)

| Peran | Email | Yang bisa dicoba |
|---|---|---|
| Administrator | admin@neoapparel.test | DSS, token API, semua modul |
| Manager | manager@neoapparel.test | Persetujuan, MIS, basis aturan pakar |
| Staf operasional | staff@neoapparel.test | Pesanan, persediaan, tugas, Pusat Pakar |
| Seller | seller@neoapparel.test | Produk, variasi, atribut gaya |
| Pembeli | budi@neoapparel.test (juga citra/dewa/eka/fajar@) | Katalog, Neo Stylist, keranjang, pesanan |

Matikan kotak akun demo di halaman login dengan `'show_demo_accounts' => false` pada `config.local.php`.

## Yang berubah dari versi awal

- **Rebrand** menjadi *Neo Apparel*; konfigurasi terpusat di `config.php` (+ `config.local.php`).
- **UI/UX baru**: satu sistem desain (`assets/neo.css`), sidebar berikon, responsif/mobile, notifikasi toast, halaman login baru, ilustrasi produk SVG otomatis (tanpa foto pun katalog tetap menarik).
- **Katalog**: produk dikelompokkan, pilih warna/ukuran di kartu, filter kategori, cari, urut, badge *Terlaris/Baru/Habis*, rating.
- **Keranjang & pesanan**: stepper jumlah, kupon (`NEOHEMAT`, `ONGKIRGRATIS`), timeline status pesanan, pembeli dapat membatalkan pesanan belum dibayar (stok kembali), pembayaran simulasi.
- **Produk**: edit variasi, aktif/nonaktif variasi, mutasi stok otomatis saat stok diubah, validasi unggah gambar (MIME asli), atribut gaya untuk Neo Stylist.
- **Keamanan & kualitas**: cookie sesi `HttpOnly/SameSite`, regenerasi sesi saat login, pembatasan percobaan login, `uploads/` tanpa eksekusi PHP, file pustaka internal diblokir `.htaccess`, indeks tambahan, perbaikan view MIS (omzet tidak lagi terhitung ganda), perbaikan bentrok variabel di layout.
- **Expert System** baru (lihat bawah) beserta seed data lengkap.

## Expert System

Menu: **Sistem Pakar → Pusat Pakar / Basis aturan / Memori fakta / Neo Stylist**.

### Arsitektur

```
 TPS  OAS  KMS  MIS  DSS            ← pengumpul fakta (es_engine.php)
   \   |    |    |   /
    Working memory (entitas + fakta)
            │  forward chaining (aturan derive, berulang sampai stabil)
            ▼
    Aturan inferensi + certainty factor (MYCIN), resolusi konflik by prioritas
            │
            ▼
    Kesimpulan + penjelasan "mengapa" (es_inferences: fakta + jejak aturan)
            │  es_actions.php
            ▼
 OAS tugas/notifikasi · DSS evaluasi SAW · KMS rujukan/permintaan/pembelajaran · TPS pembatalan order
```

| Modul | Fakta yang dibaca | Tindakan yang dikirim balik |
|---|---|---|
| **TPS** | stok, kecepatan jual 7/30 hari, tren, cover hari, keranjang, PO masuk, rating; umur order, SLA | pembatalan order belum bayar (manual), rekomendasi promo/restock |
| **OAS** | tugas terlambat, persetujuan tertunda | tugas otomatis ke staf dengan beban terendah, notifikasi per peran |
| **KMS** | permintaan pengetahuan, artikel usang | tautan SOP terkait; celah → permintaan KMS; penyelesaian → draf artikel *lessons learned* |
| **MIS** | omzet vs target, laju ideal, tren WoW, pembatalan, konsentrasi produk | peringatan manajemen + widget di Ringkasan MIS |
| **DSS** | penawaran aktif, keputusan SAW disetujui, kinerja supplier (on-time, penerimaan) | membuat evaluasi SAW otomatis dari penawaran aktif; menautkan keputusan DSS ke langkah PO |

Contoh rantai lintas modul: `stok kritis` (TPS) + `tidak ada PO` + `keputusan DSS belum ada` + `≥2 penawaran` ⇒ sistem membuat **evaluasi DSS**, **tugas OAS** untuk admin, **notifikasi** manager, dan menautkan **SOP Restock** dari KMS. Bila keputusan DSS sudah disetujui, kesimpulan berubah menjadi "Buat PO ke supplier terpilih".

### Basis pengetahuan

- 59 aturan (derive / inference / recommend) di tabel `es_rules`, dikelola di **Basis aturan** (editor JSON + uji pada data nyata + konsol penalaran ad-hoc).
- 26 parameter ambang (`es_parameters`) dapat diubah tanpa menyunting aturan: `{"param":"critical_cover_days"}`.
- Format kondisi: `{"all":[…]}`, `{"any":[…]}`, `{"not":…}`, daun `{"f":"stock","op":"<=","v":5}`; operator `== != > >= < <= in not_in between contains exists is_null`.
- Templat pesan: `{product_name}`, `{days_of_cover|n1}`, `{price|rp}` (format `rp int n1 n2 pct days`).

### Menjalankan inferensi

- **Manual**: tombol di Pusat Pakar.
- **Event-driven**: otomatis setelah checkout, pembayaran, pembatalan, perubahan status/stok, dan persetujuan DSS (`es_fire_event`; dapat dimatikan lewat parameter `event_driven_enabled`).
- **Terjadwal**: `php es_cron.php` (contoh cron: `*/30 * * * * php /path/neo_apparel/es_cron.php`).
- **API**: lihat di bawah.

Kesimpulan bersifat idempoten (satu baris per aturan+subjek); kondisi yang sudah tidak berlaku ditutup otomatis, kesimpulan yang diabaikan "tidur" 7 hari.

### Neo Stylist (untuk pembeli)

Jawaban pembeli menjadi fakta; 20 aturan domain `STYLE` memberi bobot pada kategori/atribut produk (`es_product_attributes`). Hasil: skor kecocokan, alasan yang bisa dibaca, pilihan varian (ukuran/warna/anggaran), dan padu padan atasan+celana dengan harmoni warna.

### REST API (`es_api.php`)

Autentikasi: sesi login, atau `Authorization: Bearer <token>` (token dikelola admin di *Basis aturan → Integrasi API*). Token demo: `neo-demo-token-2026` (**hapus di produksi**).

```bash
curl -H "Authorization: Bearer neo-demo-token-2026" "http://localhost/neo_apparel/es_api.php?action=inferences&severity=high"
curl -X POST -H "Authorization: Bearer neo-demo-token-2026" -H "Content-Type: application/json" \
  -d '{"subject":"variation","facts":{"stock":3,"velocity_30d":1.1,"days_of_cover":2.7}}' \
  "http://localhost/neo_apparel/es_api.php?action=consult"
```
Aksi: `health`, `inferences`, `inference`, `rules`, `facts`, `run`, `consult`, `stylist`, `inference_status`.

## Data demo (seed)

Dibuat relatif terhadap waktu impor sehingga dashboard selalu "segar": 10 pengguna, 14 produk / 90 variasi, 380 pesanan 60 hari terakhir (semua status), mutasi stok konsisten, 5 supplier + penawaran, 9 PO historis, 2 evaluasi DSS, tugas, dokumen & persetujuan, 13 artikel KMS. Skenario sengaja ditanam agar sistem pakar punya sesuatu untuk disimpulkan: stok kritis dengan/tanpa keputusan DSS, lonjakan permintaan, PO dalam perjalanan, dead stock, overstock, margin tipis, keluhan kualitas, pesanan belum dibayar, SLA pengiriman terlewat, paket tertahan, persetujuan tertunda, dan supplier berisiko.

Regenerasi seed: lihat `sql/README_SQL.txt`.

## Struktur penting

```
config.php · db.php · functions.php · layout_top/bottom.php · sidebar.php
es_engine.php  es_actions.php  es_stylist_engine.php   ← inti Expert System
es_dashboard.php  es_inference.php  es_rules.php  es_facts.php  es_stylist.php  es_api.php  es_cron.php
catalog.php cart.php payment.php my_orders.php products.php office.php …   ← TPS/OAS
mis_*.php  dss_*.php  kms_*.php  knowledge*.php                              ← MIS/DSS/KMS
sql/  assets/neo.css  uploads/
```

## Catatan produksi

Ganti semua kata sandi demo, hapus token API demo, set `show_demo_accounts` ke `false`, aktifkan HTTPS, dan arahkan web root hanya ke folder aplikasi. Pembayaran masih **simulasi** (tidak ada gateway nyata).
