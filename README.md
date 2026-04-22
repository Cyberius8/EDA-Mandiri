# 📊 GMM Raceboard Dashboard
Aplikasi dashboard berbasis **Streamlit** untuk memonitor performa **cabang** dan **pegawai** dalam program **GMM Raceboard**. Sistem ini menyediakan leaderboard interaktif, analisis KPI, serta fitur administrasi untuk pengelolaan data secara terpusat.

## 🚀 Fitur Utama

### 🏆 Leaderboard
- Ranking **Cabang** dan **Pegawai**
- Multi kategori:
  - 📱 LIVIN
  - 🏪 MERCHANT
  - 💳 TRANSAKSI
- Sorting otomatis berdasarkan KPI utama & secondary

### 📊 Dashboard Summary
- Menampilkan:
  - Top 3 performa terbaik
  - Bottom 3 performa terendah
- Tersedia untuk semua kategori

### 👤 Profil Detail
- Detail lengkap performa pegawai & cabang
- Ranking global otomatis
- Breakdown KPI:
  - End Balance
  - CIF
  - Referral
  - Transaksi (On Us vs Off Us)

### 🔍 Pencarian
- Cari berdasarkan:
  - NIP Pegawai
  - Nama Pegawai
  - Nama Cabang
- Mendukung pencarian terintegrasi

### 🔐 Login System
- Login menggunakan NIP
- Role:
  - User
  - Admin

### ⚙️ Admin Panel
- Upload & import data Excel
- Monitoring log akses user
- Reset / hapus database
- Rekap kunjungan user

### 📝 Logging System
- Mencatat:
  - Waktu akses
  - NIP & Nama
  - IP Address
- Disimpan ke:
  - SQLite (lokal)
  - Google Sheets


## 🗂️ Struktur Database
Menggunakan **SQLite** dengan tabel utama:

- `pegawai` → Data performa pegawai
- `cabang` → Data cabang & area
- `access_log` → Riwayat akses user



## 📥 Format Input Data
Upload file Excel dengan struktur:

- **Sheet GMM LIVIN**
- **Sheet GMM MERCHANT**
- **Sheet GMM TRANSAKSI**

Semua data akan digabung berdasarkan **NIP pegawai**.


## ⚙️ Teknologi
- Python
- Streamlit
- SQLite
- Pandas
- GSpread (Google Sheets API)



## 🎨 UI/UX
- Responsive (Mobile & Desktop)
- Custom CSS (modern dashboard style)
- Navigasi dinamis dengan `session_state`


## ▶️ Cara Menjalankan
1. Install dependencies:
   pip install streamlit pandas gspread openpyxl
2. Jalankan aplikasi:
   streamlit run leaderboardv9z.py
3. Akses di browser:
   http://localhost:8501

## 🔐 Konfigurasi Tambahan
Buat file `.streamlit/secrets.toml`:

admin_nip = "ISI_NIP_ADMIN"
admin_pass = "ISI_PASSWORD_ADMIN"

[gcp_service_account] isi credential Google Service Account

## 📌 Catatan
* Sistem menggunakan **WAL mode** pada SQLite untuk meningkatkan performa concurrency
* Mendukung import data multi-sheet sekaligus
* Ranking menggunakan primary & secondary metric (tie-breaker)

## 👨‍💻 Author
Developed by **Gede Darmawan**

## 📄 License
This project is for internal / educational use.


Kalau mau next level, aku bisa bantu:
- tambahin **badge GitHub (build, version, dll)**
- atau bikin **README yang lebih “jualan” buat portfolio** 🚀
```
