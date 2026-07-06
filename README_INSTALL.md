# KasirKu — Panduan Instalasi & Aktivasi (Versi Baru)

Panduan ini menjelaskan cara memasang KasirKu di hosting PHP + MySQL (contoh:
InfinityFree, atau hosting cPanel sejenis), dan apa saja yang berubah dari
versi sebelumnya.

## Apa yang baru di versi ini

1. **Aktivasi otomatis dari aplikasi.** Anda tidak perlu lagi mengedit
   `api/config.php` secara manual. Kredensial database diketik langsung di
   layar "Aktivasi Akun Pemilik" saat aplikasi pertama kali dibuka.
2. **Konfigurasi berbasis `.env`.** Semua kredensial (host, nama database,
   user, password, dan API key) sekarang disimpan di `api/.env`, dibuat
   otomatis oleh installer — bukan lagi ditulis langsung di kode PHP.
3. **Auto-install tabel database.** Saat aktivasi, seluruh struktur tabel di
   `api/database.sql` dijalankan otomatis ke database Anda (aman dijalankan
   berkali-kali karena memakai `CREATE TABLE IF NOT EXISTS`).
4. **Instalasi terkunci setelah aktif.** Setelah aktivasi pertama berhasil,
   file `api/install.lock` dibuat dan endpoint instalasi (`api/install.php`)
   akan SELALU menolak permintaan instalasi baru — baik dari aplikasi maupun
   dari luar — sampai file `install.lock` tersebut dihapus manual oleh Anda
   lewat File Manager/FTP di server.
5. **Menu Multi Toko (cabang).** Pemilik bisa menambahkan beberapa toko lewat
   Pengaturan → Multi Toko, memilih toko aktif untuk transaksi berjalan, dan
   melihat omset harian per toko + omset bersih gabungan semua toko di menu
   Laporan.
6. **Menu Stok digabung ke menu Produk.** Tambah/ubah stok kini hanya ada di
   dalam menu "Produk & Stok" (menu Stok terpisah sudah dihapus).
7. **Navigasi menu dipindah ke bawah header.** Logo, nama toko, jam, dan info
   kasir (omset hari ini, kas laci, tombol keluar) tetap di bar paling atas;
   menu utama (Kasir/Produk/Riwayat/Laporan/Pengaturan) sekarang ada di baris
   terpisah tepat di bawahnya.

## Langkah pemasangan

1. **Buat database MySQL kosong** lewat cPanel (mis. InfinityFree → MySQL
   Databases). Catat: host, nama database, user, dan password. Anda **tidak**
   perlu mengimpor `database.sql` secara manual — ini akan dijalankan otomatis
   saat aktivasi.
2. **Unggah seluruh isi folder ini** (termasuk folder `api/`) ke `htdocs`
   (atau `public_html`) di hosting Anda, lewat File Manager cPanel atau FTP.
3. **Buka domain aplikasi Anda** di browser. Karena belum ada akun Pemilik,
   Anda akan melihat layar **Aktivasi** yang meminta:
   - Nama toko
   - Host database, nama database, user database, password database
   - PIN pemilik (4-6 digit, dua kali untuk konfirmasi)
4. Tekan **"Aktifkan & Pasang Database"**. Aplikasi akan:
   - Menguji koneksi ke database Anda,
   - Membuat seluruh tabel yang diperlukan,
   - Membuat API key acak dan menyimpannya di `api/.env`,
   - Menyimpan akun Pemilik,
   - Mengunci instalasi (`api/install.lock`) agar tidak bisa dipasang ulang
     sembarangan.
5. Setelah berhasil, Anda otomatis masuk ke aplikasi. Selesai!

## Memasang ulang dari nol (jarang diperlukan)

Karena instalasi dikunci demi keamanan, untuk memasang ulang dari nol:

1. Hapus file `api/install.lock` lewat File Manager/FTP.
2. (Opsional, jika ingin database benar-benar bersih) Kosongkan/hapus tabel
   di database lewat phpMyAdmin.
3. Muat ulang aplikasi — layar Aktivasi akan muncul kembali.

## Tentang API key & keamanan

Aplikasi ini berjalan sepenuhnya di browser (tanpa sesi login server-side),
sehingga API key yang dipakai untuk sinkronisasi pada akhirnya tetap harus
sampai ke browser agar bisa dipakai (lewat `api/runtime-config.php`, endpoint
publik yang hanya mengembalikan API key **setelah** aplikasi terinstal).
Ini bukan celah keamanan baru — persis seperti versi sebelumnya yang menanam
API key langsung di `index.html` — hanya lebih rapi karena dikelola lewat
`.env` di server dan dibuat otomatis dengan nilai acak, bukan nilai tetap
yang sama untuk semua orang.

Untuk keamanan tingkat produksi yang sesungguhnya (di mana API key TIDAK
PERNAH sampai ke browser sama sekali), diperlukan arsitektur login/sesi
server-side yang berbeda dari desain aplikasi statis ini.

## File-file penting

- `api/.env` — kredensial asli (dibuat otomatis, JANGAN dibagikan/diunggah ke
  tempat publik).
- `api/.env.example` — contoh format, untuk referensi manual jika diperlukan.
- `api/install.php` — endpoint aktivasi/instalasi (mengunci diri sendiri
  setelah sukses).
- `api/install.lock` — penanda bahwa instalasi sudah aktif & terkunci.
- `api/runtime-config.php` — endpoint publik yang memberi tahu front-end
  status instalasi & API key saat ini.
- `api/config.php` — memuat `.env` dan menyediakan konstanta konfigurasi ke
  seluruh backend.
- `api/db.php`, `api/sync.php` — logika koneksi database & sinkronisasi data
  (produk, transaksi, stok, akun, **toko/cabang**, dst).
- `api/database.sql` — struktur tabel (dijalankan otomatis oleh installer).
- `api/.htaccess` — memblokir akses langsung ke `config.php`, `db.php`, dan
  file `.env`/`.sql`/`.lock` dari browser.
