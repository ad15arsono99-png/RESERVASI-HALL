# 🏢 Graha Serbaguna
### Web-Based Hall Reservation System

Aplikasi reservasi hall berbasis web yang dirancang untuk mempermudah pelanggan dalam mencari hall, melihat ketersediaan jadwal, melakukan pemesanan, mengelola pembayaran, serta memantau status reservasi secara online.

**Graha Serbaguna** menyediakan sistem manajemen reservasi terintegrasi untuk pelanggan, admin, dan owner, sehingga proses pengelolaan pemesanan hall menjadi lebih efisien, terorganisasi, dan transparan.

---

## ✨ Features

### 👤 Customer
- **User Registration & Login** — Membuat akun dan mengakses sistem reservasi.
- **Hall Catalog** — Melihat daftar hall, informasi, harga, dan fasilitas yang tersedia.
- **Online Booking** — Melakukan pemesanan hall berdasarkan tanggal dan slot waktu.
- **Availability Calendar** — Melihat ketersediaan hall untuk menghindari pemesanan pada jadwal yang sudah terisi.
- **Payment Management** — Mengunggah bukti pembayaran DP dan pelunasan.
- **Booking History** — Melihat riwayat serta status reservasi.
- **Cancellation Request** — Mengajukan pembatalan reservasi berdasarkan kebijakan yang berlaku.

### 🛠️ Admin
- **Dashboard** — Melihat informasi reservasi dan aktivitas sistem.
- **Booking Verification** — Memverifikasi pembayaran pelanggan.
- **Hall Management** — Menambah, mengubah, dan mengelola data hall.
- **Facility Management** — Mengelola fasilitas yang tersedia pada hall.
- **Cancellation Management** — Mengelola permintaan pembatalan dan proses refund.

### 📊 Owner
- **Reservation Reports** — Melihat laporan reservasi.
- **Booking Overview** — Memantau aktivitas pemesanan dan status pembayaran.

---

## 🧰 Tech Stack

| Technology | Description |
|---|---|
| PHP 8+ | Backend development |
| PDO | Database connection and query management |
| MySQL / MariaDB | Relational database |
| Bootstrap 5 | Responsive user interface |
| JavaScript | Interactive functionality |
| HTML5 & CSS3 | Frontend development |
| XAMPP | Local development environment |

---

## 🚀 Installation & Setup

Aplikasi ini dirancang untuk dijalankan secara lokal menggunakan **XAMPP**.

### 1. Install XAMPP

Download dan install XAMPP melalui website resmi:

https://www.apachefriends.org/

Pastikan Apache dan MySQL tersedia pada XAMPP Control Panel.

### 2. Clone Repository

Clone repository ini ke folder `htdocs` milik XAMPP.

```bash
git clone https://github.com/USERNAME/REPOSITORY-NAME.git
```

Atau download repository dalam format ZIP, kemudian extract ke folder:

```text
C:\xampp\htdocs\
```

Pastikan folder aplikasi bernama:

```text
reservasi-hall
```

Struktur path aplikasi:

```text
C:\xampp\htdocs\reservasi-hall\
```

### 3. Start Apache & MySQL

Buka XAMPP Control Panel, kemudian klik **Start** pada:

- Apache
- MySQL

### 4. Import Database

Database aplikasi tersedia dalam file:

```text
database.sql
```

Langkah import database menggunakan phpMyAdmin:

1. Buka browser.
2. Akses:

   ```text
   http://localhost/phpmyadmin
   ```

3. Buat database baru sesuai nama database yang dikonfigurasi pada aplikasi.
4. Pilih database yang telah dibuat.
5. Klik menu **Import**.
6. Pilih file `database.sql` dari repository.
7. Klik **Import** atau **Go**.
8. Tunggu hingga proses import selesai dan tabel database berhasil dibuat.

> Pastikan database berhasil diimport sebelum menjalankan aplikasi.

### 5. Configure Database

Buka file konfigurasi:

```text
config/config.php
```

Sesuaikan konfigurasi database dengan environment XAMPP lokal Anda.

Contoh konfigurasi:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'reservasi_hall');
define('DB_USER', 'root');
define('DB_PASS', '');
```

Sesuaikan juga konfigurasi `BASE_URL`:

```php
define('BASE_URL', '/reservasi-hall');
```

Pastikan nama database yang digunakan pada konfigurasi sesuai dengan database yang telah dibuat dan diimport melalui phpMyAdmin.

Sesuaikan juga konfigurasi informasi pembayaran (`BANK_INFO`) apabila diperlukan.

### 6. Folder Permissions

Pastikan folder berikut dapat digunakan untuk menyimpan file upload:

```text
storage/bukti/
assets/uploads/halls/
```

Folder `storage/bukti/` digunakan untuk menyimpan bukti pembayaran pelanggan, sedangkan `assets/uploads/halls/` digunakan untuk menyimpan gambar hall.

### 7. Run Application

Buka browser dan akses:

```text
http://localhost/reservasi-hall/
```

Aplikasi Graha Serbaguna siap digunakan.

---

## 🔑 Demo Accounts

Gunakan akun berikut untuk mencoba fitur aplikasi berdasarkan role.

| Role | Email | Password |
|---|---|---|
| Admin | admin@hall.test | Admin123! |
| Owner | owner@hall.test | Owner123! |
| Customer | budi@hall.test | Customer123! |
| Customer | sari@hall.test | Customer123! |

> Akun di atas merupakan akun demo. Ganti password sebelum aplikasi digunakan pada lingkungan produksi.

---

## 📁 Project Structure

```text
reservasi-hall/
│
├── config/
│   ├── config.php
│   └── database.php
│
├── includes/
│   ├── init.php
│   ├── header.php
│   ├── footer.php
│   └── admin_actions.php
│
├── api/
│   └── availability.php
│
├── assets/
│   ├── css/
│   │   └── style.css
│   ├── js/
│   │   ├── kalender.js
│   │   ├── booking.js
│   │   ├── riwayat.js
│   │   └── app.js
│   └── uploads/
│       └── halls/
│
├── storage/
│   └── bukti/
│
├── customer/
│   ├── riwayat.php
│   ├── bayar.php
│   └── proses_batal.php
│
├── admin/
│   ├── dashboard.php
│   ├── verifikasi.php
│   ├── halls.php
│   └── fasilitas.php
│
├── owner/
│   └── laporan.php
│
├── index.php
├── booking.php
├── login.php
├── register.php
├── logout.php
├── bukti.php
└── database.sql
```

---

## ⚙️ Business Logic

### Booking & Availability

Sistem menerapkan mekanisme penguncian slot reservasi untuk mencegah terjadinya double booking.

Slot hall terkunci apabila reservasi memiliki status:

- `DP Terkonfirmasi`
- `Lunas`

Pencegahan double booking dilakukan melalui beberapa mekanisme:

- Pengecekan ketersediaan slot pada proses booking.
- Transaction pada database.
- Locking menggunakan `SELECT ... FOR UPDATE`.
- Pengecekan ulang saat admin melakukan verifikasi pembayaran DP.
- Unique index pada database untuk kombinasi hall, tanggal acara, dan slot yang terkunci.

### Payment

Sistem mendukung pembayaran uang muka (DP) dan pelunasan.

Pelanggan dapat mengunggah bukti pembayaran yang kemudian diverifikasi oleh admin sebelum status pembayaran diperbarui.

### Cancellation & Refund

Sistem menyediakan mekanisme pembatalan reservasi dan perhitungan refund berdasarkan kebijakan yang berlaku.

| Waktu Pembatalan | Persentase Refund |
|---|---:|
| Lebih dari 30 hari sebelum acara | 75% |
| 14–30 hari sebelum acara | 50% |
| Kurang dari 14 hari sebelum acara | 0% |
| Pembatalan oleh pengelola | 100% |

Persentase refund dapat disesuaikan melalui konfigurasi aplikasi.

Permintaan pembatalan oleh pelanggan diproses melalui admin. Slot reservasi akan tersedia kembali setelah pembatalan disetujui dan status booking diperbarui.

---

## 🔐 Security

Aplikasi menerapkan beberapa mekanisme keamanan untuk melindungi data dan transaksi pengguna.

- **Prepared Statements** — Mencegah SQL Injection.
- **Password Hashing** — Menggunakan `password_hash()` dan `password_verify()`.
- **CSRF Protection** — Token CSRF pada request POST.
- **Session Security** — Session cookie menggunakan HttpOnly dan SameSite.
- **File Upload Validation** — Validasi ukuran file, MIME type, dan konten gambar.
- **Randomized File Names** — Menggunakan nama file acak untuk file upload.
- **Private Storage** — Bukti pembayaran disimpan pada folder privat.
- **Access Control** — Pembatasan akses berdasarkan role pengguna.
- **Output Escaping** — Mencegah Cross-Site Scripting (XSS).
- **Login Attempt Limiting** — Pembatasan percobaan login.
- **Open Redirect Protection** — Pencegahan redirect yang tidak aman.
- **Server-side Price Validation** — Harga dihitung kembali dari database dan tidak mempercayai nilai dari browser.

---

## 📸 Application Preview

Tambahkan screenshot aplikasi pada folder repository agar pengunjung GitHub dapat melihat tampilan sistem.

Contoh struktur:

```text
screenshots/
├── homepage.png
├── hall-detail.png
├── booking.png
├── customer-dashboard.png
├── admin-dashboard.png
└── owner-report.png
```

Kemudian tampilkan screenshot pada README:

```markdown
![Homepage](screenshots/homepage.png)
```

---

## 🌐 Notes

- Aplikasi dijalankan secara lokal menggunakan XAMPP.
- PHP 8+ diperlukan.
- Database menggunakan MySQL atau MariaDB.
- Bootstrap, Bootstrap Icons, dan Chart.js dimuat melalui CDN jsDelivr.
- Koneksi internet diperlukan untuk memuat library frontend dari CDN.
- Untuk penggunaan produksi, konfigurasi database, password, dan keamanan server perlu disesuaikan.

---

## 🎯 Project Purpose

Graha Serbaguna dikembangkan sebagai aplikasi web reservasi hall untuk mendigitalisasi proses pemesanan, pengelolaan jadwal, pembayaran, pembatalan, serta pelaporan reservasi.

Proyek ini juga merupakan implementasi pengembangan web menggunakan PHP, MySQL, dan Bootstrap, dengan fokus pada pengelolaan transaksi, keamanan aplikasi, validasi data, dan role-based access control.

---

## 👨‍💻 Developer

Developed as a Web-Based Hall Reservation System project.

**Graha Serbaguna — Simplifying Hall Reservation Management.**

![PHP](https://img.shields.io/badge/PHP-8%2B-777BB4?logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white)
![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?logo=bootstrap&logoColor=white)
![Platform](https://img.shields.io/badge/Platform-XAMPP-orange)
