
# 🏦 Dashboard Jaringan Cabang & SDM — README (PowerPoint-Style)

> **Versi:** DB-only (read-only) • **File DB:** `bank_dashboard.db` • **Routes:** `dashboard`, `map`, `detailb`/`detail`, `detaile`  
> **Tech:** Streamlit · Folium · OSRM · Plotly · SQLite · Pandas

---

## Slide 1 — Gambaran Umum
- Aplikasi **Streamlit** untuk memantau jaringan cabang & SDM.
- **Sumber data tunggal:** SQLite `bank_dashboard.db` (tanpa fitur upload).
- **4 halaman utama:**
  - **Dashboard** — ringkasan KPI & chart.
  - **Distribution Branch (Map)** — peta interaktif + **rute OSRM**.
  - **Detail Branch** — daftar pegawai per unit.
  - **Detail Employee** — profil IG-style + **Matriks PL/TC 2022–2024**.
- **Tema gelap + kartu** dengan CSS kustom.

---

## Slide 2 — Fitur Kunci
- 🌗 **Tema Gelap**: tampilan modern, konsisten antar halaman.
- 🗺️ **Peta Folium**: marker cabang (ikon berbeda untuk Start/End), popup kaya informasi.
- 🧭 **Rute OSRM**: tampilkan beberapa alternatif rute dengan jarak & estimasi waktu.
- 🔎 **Navigasi Query Param**: deep-link antar halaman (`route`, `unit`, `start_unit`, `end_unit`, `nip`, `nama`).
- 🧑‍💼 **Profil Pegawai IG-style**: avatar (auto dari NIP), chip info, statistik karir, matriks PL/TC.
- ⚡ **Cache Pintar**: invalidasi otomatis jika `bank_dashboard.db` berubah (pakai `mtime`).

---

## Slide 3 — Arsitektur & Teknologi
- **Frontend**: Streamlit + CSS kustom (tema gelap, card & table styling).
- **Peta**: Folium (`CartoDB positron` tiles), popup HTML, polyline rute.
- **Grafik**: Plotly (donut, bar horizontal, radar/spider).
- **Data**: SQLite (`bank_dashboard.db`) dengan tabel **`branches`** dan **`employees`**.
- **Routing**: via query params (`?route=...`), dikombinasikan dengan session state.
- **Eksternal**: **OSRM public API** untuk rute mengemudi.

> _Env var opsional:_ `APP_BASE_URL` untuk membangun tautan absolut (deep-link antar halaman).

---

## Slide 4 — Persyaratan & Instalasi
**Persyaratan**
- Python 3.9+ (punya `zoneinfo`), koneksi internet untuk panggilan OSRM.

**Instalasi**
```bash
# 1) Buat dan aktifkan lingkungan
python -m venv .venv && source .venv/bin/activate  # (Windows: .venv\Scripts\activate)

# 2) Pasang dependensi
pip install streamlit pandas numpy plotly requests folium streamlit-folium

# 3) Siapkan database
#   Pastikan ada file bank_dashboard.db dengan tabel 'branches' dan 'employees'
#   (lihat minimal schema di Slide 5)
```

**Menjalankan Aplikasi**
```bash
streamlit run app4.py
# buka: http://localhost:8501
```

---

## Slide 5 — Skema Minimal Database
**Tabel `branches` (minimal):**
- Kolom **nama unit** (terdeteksi heuristik: salah satu dari `["Unit Kerja","Kantor","Nama Cabang","Nama Unit"]`).
- Koordinat: **`Latitude` & `Longitude`** _atau_ `Latitude_Longitude` (contoh: `" -8.65, 115.22 "`).  
- Disarankan: `AREA`, `KODE_CABANG`, `Kelas Cabang`, `Izin BI`, `Status Gedung`, `Kota/Kab.`.

**Tabel `employees` (minimal):**
- Kolom standar (lihat `EMP_COLS`): `NIP`, `Nama`, `Gender`, `Posisi`, `Unit Kerja`, `Dep`, `Area`, `Status Jabatan`, `Status Pegawai`, `Birthdate`, `Source Pegawai`.

**Contoh SQL Minimal** (sekadar referensi):
```sql
CREATE TABLE branches (
  "Unit Kerja" TEXT,
  Latitude REAL,
  Longitude REAL,
  AREA TEXT
);

CREATE TABLE employees (
  NIP TEXT,
  Nama TEXT,
  Gender TEXT,
  Posisi TEXT,
  "Unit Kerja" TEXT,
  Dep TEXT,
  Area TEXT,
  "Status Jabatan" TEXT,
  "Status Pegawai" TEXT,
  Birthdate TEXT,
  "Source Pegawai" TEXT,
  "PL-2022" TEXT, "TC-2022" TEXT,
  "PL-2023" TEXT, "TC-2023" TEXT,
  "PL-2024" TEXT, "TC-2024" TEXT
);
```

> **Catatan:** Kolom tambahan seperti Grade/Level/Job Grade, dsb. akan otomatis ditampilkan jika ada.

---

## Slide 6 — Routing & Query Params
- `?route=dashboard` — Ringkasan keseluruhan + filter **Area**.
- `?route=map` — Peta interaktif: **cari cabang**, **pilih Area (zoom)**, **rute Start→Tujuan**.
  - Tambahan: `start_unit=...&end_unit=...` untuk prefill rute.
- `?route=detailb&unit=...` _atau_ `?route=detail&unit=...` — Daftar pegawai pada unit tertentu.
- `?route=detaile&nip=...` _atau_ `?route=detaile&nama=...` — Buka profil pegawai.

**Contoh Deep Link**
```text
/?route=map&start_unit=KANTOR%20CABANG%20KUTA%20RAYA&end_unit=KANTOR%20CABANG%20DENPASAR
/?route=detailb&unit=KANTOR%20CABANG%20KUTA%20RAYA
/?route=detaile&nip=2096734637
```

---

## Slide 7 — Dashboard (Ringkasan)
- **KPI Dinamis**: Pimpinan, Pelaksana, Kriya, TAD, Total Unit (terfilter Area).
- **Chart**: Donut Gender, Donut Status Pegawai, Bar Source Pegawai, Bar Status Jabatan.
- **Tabel**: Jumlah pegawai per unit + tombol **Lihat** (deep-link ke Detail Branch).
- **Filter Area**: adaptif—mencari kolom area di `branches`/`employees`.

> _Tip:_ Gunakan `APP_BASE_URL` untuk tautan absolut bila aplikasi di-deploy di subpath.

---

## Slide 8 — Distribution Branch (Map)
- **Kiri (Fokus Lokasi):**
  - **Cari cabang** (dropdown): zoom langsung ke cabang; **rute disembunyikan sementara**.
  - **Tombol Area**: zoom ke pusat area (mean lat/lon).
- **Kanan (Rute Start→Tujuan):**
  - Pilih **Start** & **End** dari daftar unit.
  - Tombol **Bersihkan Rute** untuk reset.
- **Peta:**
  - Marker berwarna per **Area** (heuristik), ikon berbeda untuk **Start/End**.
  - **Popup kaya**: info cabang, pimpinan, ringkasan SDM, tautan Google Maps, Detail, Set Start/End.

> **Rute OSRM**: menampilkan beberapa alternatif; tooltip berisi **jarak** & **kisaran waktu** (OSRM → 2×OSRM).

---

## Slide 9 — Detail Branch
- Pilih **Unit** (atau dari klik marker peta → preselect).
- **Filter tambahan**: Gender, Status Pegawai, Status Jabatan.
- **Tabel pegawai** (kolom inti) + tombol **Lihat** untuk membuka profil pegawai.
- **Chart cepat**: Gender, Status Pegawai, Status Jabatan, Source Pegawai (jika kolom tersedia).

---

## Slide 10 — Detail Employee (IG-style)
- **Avatar otomatis** dari `NIP` → URL: `https://www.mandiritams.com/mandiri_media/photo/{photo_id}.jpg`  
  - `photo_id` = **6 digit terakhir NIP lalu buang 1 digit** (mis. `2096734637` → `73463`).  
  - Pegawai **Kriya/TAD**: avatar diganti placeholder.
- **Chip Info**: Posisi, Unit, Status, Status Jabatan, Gender, Grade/Level.
- **Mini Info Grid**: Dep, Area, Kelas/Kode Cabang, Homebase, Contract, Title/Level, Agama, Birthdate/Usia.
- **Matriks PL/TC (2022–2024)** + **Statistik Karir** (TMT/Lama di grade/posisi/group/lokasi/pensiun).

---

## Slide 11 — UX & Styling
- **Tema gelap** berbasis CSS kustom (variabel: `--bg`, `--panel`, `--muted`).
- **Komponen kunci**:
  - **KPI cards** (gradasi biru/ungu/hijau).
  - **Tabel** bergaya card & tombol aksi gradien.
  - **IG-profile** komposit (header, chips, stats, grid mini).
- **Responsif** untuk layar sedang-kecil (grid → 2 kolom).

---

## Slide 12 — Performa & Caching
- `@st.cache_data` di:
  - `read_table_cached()` — cache baca tabel per `mtime` DB.
  - Parsing latlon & normalisasi unit untuk join cepat.
- **Invalidasi otomatis** bila `bank_dashboard.db` berubah.
- Pengolahan data minimal di setiap render; state route menjaga konteks.

---

## Slide 13 — OSRM Routing Notes
- Endpoint: `https://router.project-osrm.org/route/v1/driving` (publik).  
- Param: `overview=full&geometries=geojson&steps=true&alternatives=true`.
- **Keterbatasan**:
  - Rate limit & _availability_ milik layanan publik—**gunakan cache/retry** jika diperlukan.
  - Estimasi waktu ditampilkan sebagai **rentang** (OSRM → **2× OSRM**).

---

## Slide 14 — Query Params & Deep Links
- **Global**: `route` ∈ `{dashboard, map, detailb/detail, detaile}`.
- **Map**: `start_unit`, `end_unit`.
- **Detail Branch**: `unit`.
- **Detail Employee**: `nip` **atau** `nama` (persis).

**Contoh praktis**:
```text
# Fokus peta ke sebuah cabang (pilih dari dropdown kiri untuk zoom)
/?route=map

# Pra-isi rute start → end
/?route=map&start_unit=KC%20KUTA%20RAYA&end_unit=KC%20DENPASAR

# Buka daftar pegawai untuk unit tertentu
/?route=detailb&unit=KC%20KUTA%20RAYA

# Buka profil seorang pegawai via NIP
/?route=detaile&nip=2096734637
```

---

## Slide 15 — Troubleshooting
- **Peta kosong / tidak ada marker** → Pastikan `branches` punya `Latitude` & `Longitude`
  (atau `Latitude_Longitude` yang valid, _mis. `-8.65, 115.22`_).
- **Rute tidak tampil** → Pilih **Start** **dan** **Tujuan**; atau klik **Bersihkan Rute** lalu pilih ulang.
- **Foto pegawai tidak muncul** → Periksa aturan `photo_id` dari `NIP`; atau sediakan kolom URL foto eksplisit
  (kolom bernama: `foto/photo/image/img/url_foto/link_foto/photo_url/foto_url`).
- **KPI nol / chart kosong** → Cek kolom `Status Pegawai`, `Gender`, dan pemetaan huruf besar/kecil.
- **Area filter tidak muncul** → Pastikan ada salah satu kolom: `Area/AREA/Wilayah/Regional/Kanwil/Area/Kanwil`.

---

## Slide 16 — Kustomisasi & Ekstensi
- **Kolom tambahan** otomatis ikut tampil (chip & grid mini) jika ada di `employees`.
- **Pimpinan unit**: dideteksi dari `branches` (kolom: `Pimpinan/Nama Pimpinan/Kepala Cabang/...`)
  lalu fallback dari `employees` (deteksi kata kunci posisi).
- **Warna marker** dapat diubah di fungsi `_area_to_color()`.
- **Link absolut**: set `APP_BASE_URL` di `secrets.toml` atau env untuk deployment di subpath.

---

## Slide 17 — Keamanan & Privasi
- **Read-only**: aplikasi **tidak** menulis ke DB, hanya membaca & menampilkan data.
- Pastikan file DB disimpan di lokasi yang aman; pertimbangkan enkripsi/akses terbatas jika berisi data sensitif.

---

## Slide 18 — Lisensi & Kredit
- Komponen open-source: Streamlit, Folium, Plotly, OSRM.
- **Lisensi**: tentukan sesuai kebutuhan proyek Anda (MIT/BSD/Proprietary).

---

## Slide Lampiran — Referensi Fungsi Penting
- `load_data()` → baca dan siapkan data (ensure lat/lon, normalisasi unit).
- `page_dashboard()` → KPI + chart + tabel per unit.
- `page_distribution_branch()` → peta + search + Area zoom + rute OSRM.
- `page_detail_branch()` → filter & daftar pegawai per unit + chart.
- `page_detail_employee()` → profil IG-style, PL/TC, statistik karir.
- Helper: `_osrm_route()`, `_initial_bearing()`, `nip_to_photo_id()`, `photo_url_from_row()`,
  `attach_unit_norm()`, `pick_branch_unit_col()`, `link_detail()`, dsb.

---

### 🧩 Appendix — Snippet Jalankan Cepat
```bash
pip install streamlit pandas numpy plotly requests folium streamlit-folium
streamlit run app4.py
```
