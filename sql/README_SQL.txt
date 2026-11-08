NEO APPAREL - BERKAS SQL
========================
Instalasi cepat (urut):
  1. install_kms.sql   -> db_neo_apparel_kms  (skema + seed KMS)
  2. install_main.sql  -> db_neo_apparel      (skema TPS/OAS/MIS/DSS + Expert System + seed + aturan pakar)

Impor ulang aman dilakukan kapan saja: tabel dibuat bila belum ada dan seed menghapus lalu mengisi ulang
data demo (SEMUA data pada tabel demo akan diganti). Jangan impor ke database produksi yang berisi data asli.

Berkas terpisah (sumber install_*.sql):
  01_schema_main.sql          skema TPS + OAS + MIS + DSS + view MIS
  02_schema_expert_system.sql tabel es_* + login_attempts
  03_schema_kms.sql           skema KMS
  10_seed_main.sql            pengguna, katalog, pesanan, supplier, PO, DSS, tugas, dst
  11_seed_expert_rules.sql    59 aturan, 26 parameter, token API demo
  12_seed_kms.sql             artikel, tag, ahli, permintaan KMS

Nama database dapat diganti: cari-ganti `db_neo_apparel` pada file SQL dan atur di config.local.php.
Kata sandi semua akun demo: NeoApparel123!
Tanggal seed bersifat relatif terhadap waktu impor (NOW()).

Catatan phpMyAdmin: install_main.sql berukuran ±0,5 MB (di bawah batas unggah bawaan 2 MB).
