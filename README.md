# 🏦 EDAMandiri — Dashboard Jaringan Cabang & SDM
# Branch Network & HR Dashboard

> **Catatan lisensi & distribusi**: Proyek ini untuk **pemakaian internal** dengan lisensi **MIT** (lihat bagian “Lisensi”).
> 
> **License & distribution**: This project is for **internal use** under the **MIT** license (see the “License” section).

---

## Ringkasan / Overview
Aplikasi Streamlit untuk memantau jaringan cabang (branch) dan SDM (employee): tema gelap/terang, peta distribusi cabang, detail cabang/pegawai, rute OSRM, serta modul Rotasi–Mutasi (Gerbong) termasuk ekspor PDF. Halaman utama mencakup **Dashboard**, **Distribution Branch**, **Detail Branch**, **Detail Employee**, **Rotasi/Mutasi**, dan **Update Data**.

This Streamlit app helps visualize branch and HR data: light/dark theme, branch distribution map, branch/employee details, OSRM routing, and a Rotation/Mutation ("Gerbong") module with PDF export. Main pages: **Dashboard**, **Distribution Branch**, **Detail Branch**, **Detail Employee**, **Rotation/Mutation**, and **Update Data**.

---

## Fitur Utama / Key Features
- 🌗 **Tema Gelap/Terang** (toggle) + CSS kustom lintas komponen.
- 🗺️ **Peta distribusi cabang** (Folium) + opsi render saat dibutuhkan.
- 🧭 **Rute OSRM (driving)** dengan ringkasan jarak/waktu, alternatif, dan nama ruas jalan.
- 🧑‍💼 **Detail pegawai** & 📍 **Detail cabang** via query params / navigasi cepat.
- 🗃️ **Database SQLite** fleksibel: path via ENV / `st.secrets`, fallback otomatis ke `./data/bank_dashboard.db`.
- 🔁 **Rotasi–Mutasi (Gerbong)**: CRUD gerbong & item, grade range, PL/TC, rekap tabel, dan ekspor PDF (ReportLab).

---

## Arsitektur Singkat / Quick Architecture
- **Frontend runtime**: Streamlit + CSS kustom untuk konsistensi tema.
- **Mapping**: Folium + Marker/Cluster (opsional) dan tile yang ramah tema.
- **Routing**: OSRM `route/v1/driving` (overview geojson, steps, alternatives).
- **Data**: SQLite lokal. Aplikasi akan membuat file DB bila belum ada dan menguji hak tulis direktori.

---

## Prasyarat / Prerequisites
- **Python** 3.9+ (disarankan 3.10 atau 3.11)
- Paket Python utama:
  `streamlit`, `pandas`, `numpy`, `plotly`, `folium`, `streamlit-folium`, `requests`, `reportlab`

> Catatan: `sqlite3` dan `zoneinfo` termasuk dalam distribusi Python.

---

## Instalasi / Installation
```bash
# 1) Buat dan aktifkan virtual environment
python -m venv .venv
# Windows
.venv\Scriptsctivate
# macOS/Linux
source .venv/bin/activate

# 2) Pasang dependensi
pip install -r requirements.txt
```

**Contoh `requirements.txt` / Sample**
```txt
streamlit
pandas
numpy
plotly
folium
streamlit-folium
requests
reportlab
```

---

## Konfigurasi / Configuration
Aplikasi mencoba beberapa sumber konfigurasi. Urutan prioritas umumnya: **ENV → `st.secrets` → default**.

**Database path**
- `APP_DB_PATH` **atau** `BASE_DB_PATH` (ENV)  
- `DB_PATH` (di `st.secrets`)  
- Default: `./data/bank_dashboard.db` (dibuat otomatis)

**Base URL & Prefix tautan**
- `APP_BASE_URL` **atau** `BASE_PREFIX` (ENV atau `st.secrets`) untuk membentuk tautan internal antar-halaman (mis. saat membuka detail via query params).

**Contoh `.streamlit/secrets.toml`**
```toml
# .streamlit/secrets.toml
DB_PATH = "./data/bank_dashboard.db"
APP_BASE_URL = ""
BASE_PREFIX = ""
```

> Pastikan direktori `./data/` memiliki izin tulis bila menggunakan default path.

---

## Menjalankan Lokal / Run Locally
```bash
streamlit run app8.py
```
Kemudian buka URL yang ditampilkan Streamlit (umumnya `http://localhost:8501`).

---

## Skema Data Minimal / Minimal Data Schema
Skema di bawah ini adalah **minimum** agar fitur utama berjalan. Anda boleh menambah kolom lain sesuai kebutuhan; kolom tambahan akan diabaikan oleh fitur yang tidak memerlukannya.

### 1) `employees`
| Kolom (ID) | Tipe | Keterangan |
|---|---|---|
| `NIP` | TEXT/INTEGER | **Kunci unik** pegawai. |
| `NAMA` | TEXT | Nama pegawai. |
| `GENDER` | TEXT | L/P (opsional). |
| `POSISI` | TEXT | Jabatan saat ini. |
| `UNIT` | TEXT | Unit kerja saat ini. |
| `AREA` | TEXT | Area (mis. "AREA DENPASAR"). |
| `BAND` | TEXT | Opsional. |
| `LEVEL` | TEXT | Opsional. |
| `TMT_POSISI` | TEXT/DATE | Opsional, untuk kalkulasi lama posisi. |
| `TMT_LOKASI` | TEXT/DATE | Opsional, untuk kalkulasi lama wilayah. |

> Aplikasi mendukung alias nama kolom umum (misalnya variasi huruf besar/kecil). Pastikan mapping di dalam aplikasi sesuai.

### 2) `branches`
| Kolom (ID) | Tipe | Keterangan |
|---|---|---|
| `UNIT` | TEXT | **Kunci logis** unit cabang (gunakan nama unik per unit). |
| `LAT` | REAL | Latitude. |
| `LON` | REAL | Longitude. |
| `KOTA` | TEXT | Opsional. |
| `PROVINSI` | TEXT | Opsional. |

> Jika memiliki kolom gabungan `Latitude_Longitude`, aplikasi dapat menurunkan `LAT/LON` saat update data.

### 3) `gerbong`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INTEGER (PK, AUTOINC) | ID gerbong. |
| `nama` | TEXT | Nama batch rotasi (mis. "Rotasi Q4-2025"). |
| `nomor_surat` | TEXT | Nomor surat keputusan. |
| `tgl_efektif` | DATE | Tanggal efektif rotasi. |
| `area` | TEXT | Area yang terkait. |
| `catatan` | TEXT | Catatan tambahan (opsional).

### 4) `gerbong_items`
| Kolom | Tipe | Keterangan |
|---|---|---|
| `id` | INTEGER (PK, AUTOINC) | ID item. |
| `gerbong_id` | INTEGER (FK → gerbong.id) | Relasi ke gerbong. |
| `nip` | TEXT/INTEGER | NIP pegawai. |
| `jabatan_asal` | TEXT | Jabatan asal. |
| `unit_asal` | TEXT | Unit asal. |
| `region_asal` | TEXT | Area/region asal. |
| `jabatan_tujuan` | TEXT | Jabatan tujuan. |
| `unit_tujuan` | TEXT | Unit tujuan. |
| `region_tujuan` | TEXT | Area/region tujuan. |
| `pltc` | TEXT | Opsi PL/TC (opsional). |
| `grade_range` | TEXT | Rentang grade (opsional). |
| `lama_posisi` | INTEGER | Hasil kalkulasi bulan (opsional). |
| `lama_wilayah` | INTEGER | Hasil kalkulasi bulan (opsional).

---

## Deployment

### Opsi A — Google Cloud Run (+ Secret Manager)  
Cocok untuk aplikasi internal dengan skala otomatis, HTTPS, dan integrasi kebijakan. Disarankan **Cloudflare Access** di depan Cloud Run untuk kontrol akses berbasis identitas.

1. **Dockerfile** (contoh minimal)
```dockerfile
# syntax=docker/dockerfile:1
FROM python:3.11-slim
WORKDIR /app

# Sistem deps (opsional)
RUN apt-get update && apt-get install -y --no-install-recommends     build-essential curl && rm -rf /var/lib/apt/lists/*

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt

COPY . .

# Streamlit uses port 8501 by default
ENV PORT=8501
EXPOSE 8501

# Ensure Streamlit not opening browser and headless
ENV STREAMLIT_SERVER_HEADLESS=true

CMD ["streamlit", "run", "app8.py", "--server.port=8501", "--server.address=0.0.0.0"]
```

2. **Build & Push**
```bash
gcloud builds submit --tag gcr.io/PROJECT_ID/edamandiri:latest
```

3. **Deploy**
```bash
gcloud run deploy edamandiri   --image gcr.io/PROJECT_ID/edamandiri:latest   --platform managed   --region asia-southeast2   --allow-unauthenticated=false   --set-env-vars APP_DB_PATH=/app/data/bank_dashboard.db,APP_BASE_URL=,BASE_PREFIX=
```
> Simpan rahasia (jika ada) di **Secret Manager** dan tambahkan sebagai env vars/volumes saat deploy. Pastikan direktori `/app/data` ditulis saat init (bisa dibuat di tahap build atau entrypoint).

4. **Akses Internal (Disarankan)**
- Pasang **Cloudflare Access** (atau Google IAP) untuk melindungi URL Cloud Run. Buat policy berbasis email/domain perusahaan dan tambahkan aplikasi Cloud Run sebagai origin.

### Opsi B — Railway / Render (di belakang Cloudflare Access)
1. Buat service baru dari repo.  
2. Tambah `PORT=8501`, `APP_DB_PATH=/app/data/bank_dashboard.db` dan env lain yang diperlukan.  
3. Gunakan Dockerfile di atas atau buildpack Python.  
4. Aktifkan **Cloudflare Access** ke domain publik service sebagai pagar identitas.

---

## Keamanan & Privasi / Security & Privacy
- Database SQLite dapat mengandung **data internal sensitif**; hindari commit DB nyata ke repo publik.
- Gunakan **Cloudflare Access** / IAP untuk kontrol akses, serta audit akses berkala.
- Pertimbangkan pemisahan **DB terkelola** (Cloud SQL / Neon / lain) bila beban dan kolaborasi meningkat.

---

## Cara Pakai / Usage
- Navigasi menggunakan tombol/tab halaman.
- Toggle **mode gelap/terang** di UI.
- **Map & Rute**: pilih unit asal/tujuan; ringkasan rute via OSRM.
- **Rotasi–Mutasi**: buat Gerbong, tambahkan item pegawai, unduh PDF rekap.

> **Catatan OSRM**: layanan OSRM publik tidak memerlukan API key namun dapat memiliki batasan reliabilitas/timeout. Untuk misi-kritis, pertimbangkan host OSRM sendiri.

---

## Troubleshooting
- **`StreamlitSecretNotFoundError`**: pastikan file `.streamlit/secrets.toml` tersedia atau gunakan ENV vars.
- **`too many SQL variables` saat bulk insert**: lakukan insert per-batch (mis. 500 baris per commit) atau gunakan `executemany` yang dipecah.
- **Peta tidak merender kecuali saat klik Search**: ini by design untuk menghindari re-render berat; pastikan tombol “Search” memicu rerun.

---

## Kontribusi / Contributing
Kontribusi internal dipersilakan (branch → PR). Sertakan deskripsi perubahan, catatan migrasi DB (jika ada), dan screenshot singkat untuk update UI.

---

## Lisensi / License (MIT)
Copyright (c) Internal Owner

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

---

## Kredit / Credits
- Streamlit, Folium, Plotly, Pandas, ReportLab, OSRM.
