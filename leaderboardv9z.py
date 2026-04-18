# /mnt/data/leaderboardv4.py
import sqlite3
import pandas as pd
import streamlit as st
import io
import os
import math
import re
from datetime import datetime
from zoneinfo import ZoneInfo

# 1. WAJIB DI ATAS: Konfigurasi Page Streamlit untuk Mobile
st.set_page_config(
    page_title="GMM RACEBOARD", 
    page_icon="🏆", 
    layout="wide", 
    initial_sidebar_state="collapsed"
)

DB_PATH = "ycc_leaderboard.db"

# ---------------------------
# Config Kategori KPI
# ---------------------------
def fmt_rp(value):
    try:
        v = int(round(float(value)))
        return f"Rp {v:,}".replace(",", ".") + "jt"
    except:
        return "Rp 0jt"

def fmt_num(value):
    try:
        v = int(round(float(value)))
        return f"{v:,}".replace(",", ".")
    except:
        return "0"

def fmt_pct(value):
    try:
        v = float(value)
        return f"{v:.2f}%"
    except:
        return "0.00%"

KAT_CONFIG = {
    "LIVIN": {
        "score_col": "end_balance",
        "score_label": "End Balance",
        "sec_col": "cif_akuisisi",
        "sec_label": "CIF Akuisisi",
        "fmt": fmt_rp
    },
    "MERCHANT": {
        "score_col": "total_referral_edc",
        "score_label": "Referral EDC",
        "sec_col": "total_referral_livin",
        "sec_label": "Referral LVM",
        "fmt": fmt_num
    },
    "TRANSAKSI": {
        "score_col": "pct_on_us", 
        "score_label": "% On Us",
        "sec_col": "total_poin_transaksi",
        "sec_label": "Total Poin",
        "fmt": fmt_pct 
    }
}

# ---------------------------
# Init DB & queries
# ---------------------------
# Tambahkan decorator ini agar fungsi hanya dieksekusi sekali per siklus server
@st.cache_resource
def init_db():
    # Tambahkan timeout=15.0 agar antre maksimal 15 detik sebelum error
    conn = sqlite3.connect(DB_PATH, timeout=15.0)
    
    # Aktifkan WAL (Write-Ahead Logging) untuk concurrency yang jauh lebih baik
    conn.execute("PRAGMA journal_mode=WAL;")
    
    cur = conn.cursor()
    cur.execute("""
    CREATE TABLE IF NOT EXISTS cabang (
        kode_cabang TEXT PRIMARY KEY,
        unit TEXT,
        area TEXT,
        nama_cabang TEXT,
        kelas_cabang TEXT
    )
    """)
    cur.execute("""
    CREATE TABLE IF NOT EXISTS pegawai (
        nip TEXT PRIMARY KEY,
        nama TEXT,
        kode_cabang TEXT,
        unit TEXT,
        cif_akuisisi REAL,
        pct_akuisisi REAL,
        cif_setor REAL,
        pct_setor_akuisisi REAL,
        cif_sudah_transaksi REAL,
        pct_transaksi_setor REAL,
        frek_dari_cif_akuisisi REAL,
        sv_dari_cif_akuisisi_jt REAL,
        end_balance REAL,
        rata_rata REAL,
        area TEXT,
        nama_cabang TEXT,
        posisi TEXT,
        avatar_url TEXT,
        total_referral_livin REAL DEFAULT 0,
        total_referral_edc REAL DEFAULT 0,
        total_poin_transaksi REAL DEFAULT 0,
        poin_on_us REAL DEFAULT 0,
        poin_off_us REAL DEFAULT 0,
        frek_on_us REAL DEFAULT 0,
        frek_off_us REAL DEFAULT 0,
        pct_on_us REAL DEFAULT 0,
        FOREIGN KEY (kode_cabang) REFERENCES cabang(kode_cabang)
    )
    """)
    
    # --- TABEL BARU UNTUK LOGGING ---
    cur.execute("""
    CREATE TABLE IF NOT EXISTS access_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        waktu TEXT,
        nip TEXT,
        nama TEXT,
        ip_address TEXT
    )
    """)
    
    conn.commit()
    conn.close()
def get_cabang_leaderboard(kategori="LIVIN"):
    conf = KAT_CONFIG[kategori]
    sc = conf["score_col"]
    se = conf["sec_col"]

    if kategori == "TRANSAKSI":
        # KHUSUS CABANG: Kembalikan ke akumulasi poin
        sc_expr = "SUM(p.total_poin_transaksi)"
        se_expr = "SUM(p.poin_on_us)"
        avg_sc_expr = "0" 
    else:
        sc_expr = f"SUM(p.{sc})"
        se_expr = f"SUM(p.{se})"
        avg_sc_expr = f"AVG(p.{sc})"

    conn = sqlite3.connect(DB_PATH)
    df = pd.read_sql_query(f"""
        SELECT k.kode_cabang,
               COALESCE(c.unit, k.kode_cabang) AS unit,
               COALESCE(c.area, '(Unknown)') AS area,
               COALESCE(c.kelas_cabang, '-') AS kelas_cabang,
               IFNULL({sc_expr},0) AS total_balance,
               IFNULL(COUNT(p.nip),0) AS jumlah_pegawai,
               IFNULL({avg_sc_expr},0) AS rata_rata_saldo,
               IFNULL({se_expr},0) AS total_cif
        FROM (
          SELECT kode_cabang FROM cabang 
          WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan')
          UNION
          SELECT DISTINCT kode_cabang FROM pegawai 
          WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan')
        ) k
        LEFT JOIN cabang c ON k.kode_cabang = c.kode_cabang
        LEFT JOIN pegawai p ON k.kode_cabang = p.kode_cabang
        GROUP BY k.kode_cabang
        ORDER BY total_balance DESC
    """, conn)
    conn.close()
    return df
def get_pegawai(kode, kategori="LIVIN"):
    conf = KAT_CONFIG[kategori]
    sc = conf["score_col"]
    se = conf["sec_col"]

    if kategori == "TRANSAKSI":
        # Gunakan frekuensi asli untuk persentase pegawai
        sc_expr = "(CASE WHEN (frek_on_us + frek_off_us) > 0 THEN (frek_on_us / (frek_on_us + frek_off_us)) * 100.0 ELSE 0 END)"
        se_expr = "total_poin_transaksi"
    else:
        sc_expr = sc
        se_expr = se

    conn = sqlite3.connect(DB_PATH)
    base_query = f"""
        SELECT nip, nama, kode_cabang, unit, 
               IFNULL({sc_expr},0) AS end_balance, 
               IFNULL({se_expr},0) AS cif_akuisisi, 
               posisi, area, nama_cabang, avatar_url,
               total_poin_transaksi, poin_on_us, poin_off_us, frek_on_us, frek_off_us
        FROM pegawai
        WHERE kode_cabang IS NOT NULL 
          AND TRIM(kode_cabang) != '' 
          AND LOWER(kode_cabang) NOT IN ('unknown', 'nan')
    """
    
    if kode is None or kode == "ALL":
        df = pd.read_sql_query(base_query + " ORDER BY end_balance DESC, cif_akuisisi DESC", conn)
    elif len(kode) == 3:
        df = pd.read_sql_query(base_query + " AND area = ? ORDER BY end_balance DESC, cif_akuisisi DESC", conn, params=(kode,))
    else:
        df_cabang = pd.read_sql_query("SELECT kode_cabang FROM cabang", conn)
        if kode in df_cabang['kode_cabang'].tolist():
            df = pd.read_sql_query(base_query + " AND kode_cabang = ? ORDER BY end_balance DESC, cif_akuisisi DESC", conn, params=(kode,))
        else:
            df = pd.read_sql_query(base_query + " AND area = ? ORDER BY end_balance DESC, cif_akuisisi DESC", conn, params=(kode,))
    conn.close()
    return df
def normalize_val(x):
    if pd.isna(x) or x is None: return 0
    s = str(x).strip().replace(',', '.')
    s = re.sub(r'[^\d\.-]', '', s)
    try: return float(s)
    except: return 0

# ---------------------------
# CSS Khusus Mobile & Desktop
# ---------------------------
LOGO_PATH = "https://github.com/Cyberius8/EDA-Mandiri/blob/main/R11GMM.jpg?raw=true"
ENHANCED_CSS = rf"""
<style>
/* --- STYLING STREAMLIT NATIVE BUTTONS --- */
div[data-testid="stButton"] > button[kind="secondary"] {{
    border-radius: 12px;
    border: 1px solid rgba(255, 255, 255, 0.1);
    background: linear-gradient(135deg, rgba(255,255,255,0.05), rgba(255,255,255,0.01));
    color: var(--accent);
    font-weight: 700;
    transition: all 0.3s ease;
}}

div[data-testid="stButton"] > button[kind="secondary"]:hover {{
    border-color: var(--accent);
    background: rgba(31, 182, 255, 0.1);
    color: #ffffff;
    box-shadow: 0 4px 15px rgba(31, 182, 255, 0.25);
    transform: translateY(-2px);
}}

div[data-testid="stButton"] > button[kind="secondary"]:active {{
    transform: translateY(0px);
}}

@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
:root{{ --bg1: #061526; --bg2: #0b2b46; --accent: #1fb6ff; --text: rgba(255,255,255,0.96); --muted: rgba(255,255,255,0.72); }}

/* Menghilangkan Menu Streamlit Bawaan untuk kesan App Native */
#MainMenu {{visibility: hidden;}}
header {{visibility: hidden;}}
footer {{visibility: hidden;}}
.block-container {{ padding-top: 1rem !important; padding-bottom: 1rem !important; padding-left: 0.8rem !important; padding-right: 0.8rem !important; max-width: 1200px; }}

body, .stApp {{ font-family: 'Inter', sans-serif; background: linear-gradient(180deg,var(--bg1),var(--bg2)) !important; color: var(--text) !important; }}

.header-center {{ display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px; width: 100%; }}
.logo-img {{ width:200px; height:200px; border-radius:12px; object-fit:cover; border:2px solid rgba(255,255,255,0.06); box-shadow:0 10px 28px rgba(0,0,0,0.6); transition: all 0.3s; }}
.title-pill {{ background: rgba(255,255,255,1); color: #041827; padding:12px 24px; border-radius:28px; font-weight:900; font-size:24px; box-shadow: 0 12px 30px rgba(0,0,0,0.35); border: 4px solid rgba(0,0,0,0.06); text-align:center; }}
.subtitle-small {{ color:var(--muted); font-weight:600; font-size:13px; text-align:center; }}

.leaderboard-card {{ background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.04); color: #e6eef8; }}
.medal {{ font-weight:700; font-size:0.85rem; padding:6px 10px; border-radius:12px; display:inline-block; margin-bottom:6px; box-shadow: 0 4px 10px rgba(2,6,23,0.12); }}
.medal.gold {{ background: linear-gradient(135deg, #FFD700 0%, #FFC107 60%); color: #111; }}
.medal.silver {{ background: linear-gradient(135deg, #e9eef2 0%, #cfd8dc 60%); color: #111; }}
.medal.bronze {{ background: linear-gradient(135deg, #cd7f32 0%, #b4692b 60%); color: #111; }}

.row-card {{ background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); border-radius: 12px; padding: 14px; margin-bottom: 0px; display: flex; justify-content: space-between; align-items: center; box-shadow: 0 4px 12px rgba(2,6,23,0.25); border: 1px solid rgba(255,255,255,0.03); }}
.row-left {{ display: flex; align-items: center; gap: 12px; flex: 1 1 auto; min-width: 0; }}
.rank-badge {{ width: 44px; height: 44px; flex-shrink: 0; border-radius: 12px; display:flex; align-items:center; justify-content:center; font-weight:800; font-size:16px; box-shadow: 0 4px 10px rgba(0,0,0,0.25); }}
.rank-badge.top1 {{ background: linear-gradient(135deg,#FFD700,#FFC107); color:#3A2C00; }}
.rank-badge.top2 {{ background: linear-gradient(135deg,#cfcfcf,#bfc4c8); color:#3A3A3A; }}
.rank-badge.top3 {{ background: linear-gradient(135deg,#cd7f32,#b4692b); color:#3C2500; }}

.row-meta {{ display:flex; flex-direction:column; gap:2px; min-width:0; }}
.row-meta .unit, .row-meta .name {{ font-weight:800; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color: #e6f2ff; }}
.small-muted {{ font-size:0.8rem; opacity:0.8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; color: #bcd1e6; }}
.row-right {{ flex: 0 0 auto; margin-left: 8px; text-align:right; color:#e6f2ff; font-weight:800; }}

.detail-link {{ display:inline-block; padding:6px 10px; border-radius:8px; background: rgba(255,255,255,0.05); border: 1px solid rgba(255,255,255,0.1); color:var(--accent); text-decoration:none; font-weight:700; font-size: 0.85rem; }}

/* Statistik Cards Responsive */
.stat-container {{ display: flex; gap: 10px; margin-top: 10px; margin-bottom: 18px; flex-wrap: wrap; }}
.stat-card {{ background: linear-gradient(135deg, #0F172A, #1E293B); padding: 12px 14px; border-radius: 12px; flex: 1 1 calc(50% - 10px); min-width: 140px; color: #e6eef8; box-shadow: 0 4px 10px rgba(0,0,0,0.25); }}
.stat-title {{ font-size: 0.75rem; opacity: 0.75; margin-bottom: 4px; }}
.stat-value {{ font-size: 1.25rem; font-weight: 800; margin-bottom: 2px; overflow: hidden; text-overflow: ellipsis; }}
.stat-extra {{ font-size: 0.75rem; opacity: 0.5; }}

/* Cari bagian .highlight-card dan ganti dengan ini */
.highlight-card {{
    background: linear-gradient(135deg, #FF7A00, #FF9900) !important;
    border: 2px solid #FFD180 !important;
    color: #FFFFFF !important;
    box-shadow: 0 8px 25px rgba(255, 122, 0, 0.4) !important;
    transform: scale(1.02);
    /* Tambahan agar isi konten di dalamnya rapi */
    display: flex;
    flex-direction: column;
    justify-content: center;
}}

/* Pastikan title di dalam kartu highlight berwarna putih cerah */
.highlight-card .detail-title {{ 
    color: #FFF3E0 !important; 
    opacity: 1 !important;
}}

/* Tambahkan styling khusus untuk deskripsi di bawah angka agar tidak terlalu kecil/buram */
.highlight-card .detail-value span {{
    font-size: 0.75rem !important;
    font-weight: normal !important;
    color: #FFF3E0 !important;
    display: block;
    margin-top: 5px;
    line-height: 1.2;
}}

.detail-grid {{ display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-top: 16px; }}
.detail-card {{ padding: 16px; border-radius: 16px; color: #fff; background: linear-gradient(135deg, rgba(32,51,160,0.85), rgba(72,12,168,0.90)); box-shadow: 0 8px 24px rgba(0,0,0,0.3); border: 1px solid rgba(255,255,255,0.1); }}
.detail-title {{ font-size: 0.8rem; opacity: 0.85; font-weight: 700; }}
.detail-value {{ font-size: 1.5rem; font-weight: 800; margin-top: 8px; }}
.detail-icon {{ font-size: 1.2rem; margin-bottom: 4px; opacity: 0.9; }}

.emp-banner {{ background: linear-gradient(135deg, #1f2b52, #3f1f7a); padding: 16px; border-radius: 16px; box-shadow: 0 8px 24px rgba(0,0,0,0.3); margin-bottom: 16px; display: flex; align-items: center; gap: 16px; color: #fff; }}
.emp-avatar {{ width: 64px; height: 64px; flex-shrink: 0; border-radius: 50%; background: linear-gradient(135deg, #ffffff33, #ffffff11); display: flex; align-items: center; justify-content: center; font-size: 28px; font-weight: 900; color: #fff; border: 2px solid rgba(255,255,255,0.2); }}
.emp-info-title {{ font-size: 1.2rem; font-weight: 800; line-height: 1.2; }}


/* Penyesuaian Ekstrem untuk Layar HP Kecil (Mobile) */
@media (max-width: 600px) {{
    .logo-img {{ width: 140px; height: 140px; }}
    .title-pill {{ font-size: 18px; padding: 10px 18px; }}
    .row-card {{ padding: 10px; margin-bottom: 0px; }}
    .rank-badge {{ width: 38px; height: 38px; font-size: 14px; }}
    .row-meta .unit, .row-meta .name {{ font-size: 0.85rem; }}
    .small-muted {{ font-size: 0.75rem; }}
    .row-right {{ display: flex; flex-direction: column; align-items: flex-end; gap: 6px; }}
    .detail-link {{ font-size: 0.75rem; padding: 4px 8px; }}
    .stat-card {{ padding: 10px; }}
    .stat-value {{ font-size: 1.1rem; }}
    .detail-grid {{ grid-template-columns: repeat(1, 1fr); }} /* 2 Kolom untuk HP */
    .emp-banner {{ flex-direction: column; text-align: center; justify-content: center; }}
}}

</style>
"""

init_db()

# --- INISIALISASI SESSION STATE ---
if "view" not in st.session_state: st.session_state.view = "home"
if "kode" not in st.session_state: st.session_state.kode = None
if "detail_nip" not in st.session_state: st.session_state.detail_nip = None
if "page_num" not in st.session_state: st.session_state.page_num = 1
if "is_admin" not in st.session_state: st.session_state.is_admin = False
if "show_update_panel" not in st.session_state: st.session_state.show_update_panel = False
if "kategori" not in st.session_state: st.session_state.kategori = "HOME"

# Variabel khusus Login
if "logged_in" not in st.session_state: st.session_state.logged_in = False
if "current_user_nip" not in st.session_state: st.session_state.current_user_nip = None
if "current_user_nama" not in st.session_state: st.session_state.current_user_nama = None
if "visit_count" not in st.session_state: st.session_state.visit_count = 1
if "last_visit" not in st.session_state: st.session_state.last_visit = "Ini kunjungan pertama Anda"

st.markdown(ENHANCED_CSS, unsafe_allow_html=True)

# ---------------------------
# Routing & Parameter (Deep Linking Fix)
# ---------------------------
# Membersihkan parameter query agar tidak "nyangkut" dan menimpa navigasi internal
params = st.query_params
if "kode" in params:
    st.session_state.view = "pegawai"
    st.session_state.kode = str(params.get("kode")).strip()
    st.query_params.clear()
if "view" in params:
    st.session_state.view = str(params.get("view"))
    st.query_params.clear()

# ---------------------------
# FUNGSI LOGGING & HALAMAN LOGIN
# ---------------------------
def log_visitor(nip, nama):
    try:
        headers = st.context.headers
        ip_address = headers.get("X-Forwarded-For", headers.get("Host", "Unknown_IP"))
    except:
        ip_address = "Unknown_IP"
        
    # Tambahkan timeout di sini juga
    conn = sqlite3.connect(DB_PATH, timeout=15.0)
    cur = conn.cursor()
    waktu_sekarang = datetime.now(ZoneInfo("Asia/Makassar")).strftime("%Y-%m-%d %H:%M:%S")
    cur.execute("INSERT INTO access_log (waktu, nip, nama, ip_address) VALUES (?, ?, ?, ?)", 
                (waktu_sekarang, nip, nama, ip_address))
    conn.commit()
    conn.close()
def get_visit_stats(n):
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*), MAX(waktu) FROM access_log WHERE nip = ?", (n,))
    stats = cur.fetchone()
    conn.close()
    
    visit_count = (stats[0] if stats[0] else 0) + 1 
    last_visit = stats[1] if stats[1] else "Ini kunjungan pertama Anda"
    return visit_count, last_visit

# TAMPILAN LOGIN
if not st.session_state.logged_in:
    st.markdown(f"<div class='header-center'><img src='{LOGO_PATH}' class='logo-img'/></div>", unsafe_allow_html=True)
    st.markdown("<h2 style='text-align:center; margin-top:20px;'>🔑 Login Dashboard</h2>", unsafe_allow_html=True)
    st.markdown("<p style='text-align:center; color:var(--muted);'>Masukkan NIP Anda untuk mengakses GMM Raceboard</p>", unsafe_allow_html=True)
    
    _, col_login, _ = st.columns([1, 2, 1])
    with col_login:
        with st.form("login_form"):
            nip_input = st.text_input("NIP Pegawai")
            submit_btn = st.form_submit_button("Masuk 🚀", width='stretch')
            
            if submit_btn:
                nip_clean = nip_input.strip()
                
                # Fetch nama from DB first
                conn = sqlite3.connect(DB_PATH)
                cur = conn.cursor()
                cur.execute("SELECT nama FROM pegawai WHERE nip = ?", (nip_clean,))
                user_data = cur.fetchone()
                conn.close()
                
                # Cek Admin (Menggunakan NIP Admin Spesifik atau Admin123)
                if nip_clean == "2502844731" or nip_clean.lower() == "admin123":
                    nama_admin = user_data[0] if user_data else "Administrator"
                    v_count, l_visit = get_visit_stats(nip_clean)
                    st.session_state.logged_in = True
                    st.session_state.current_user_nip = nip_clean
                    st.session_state.current_user_nama = nama_admin
                    st.session_state.is_admin = True
                    st.session_state.visit_count = v_count
                    st.session_state.last_visit = l_visit
                    log_visitor(nip_clean, nama_admin)
                    st.rerun()
                # Cek User Biasa
                elif user_data:
                    v_count, l_visit = get_visit_stats(nip_clean)
                    st.session_state.logged_in = True
                    st.session_state.current_user_nip = nip_clean
                    st.session_state.current_user_nama = user_data[0]
                    st.session_state.is_admin = False
                    st.session_state.visit_count = v_count
                    st.session_state.last_visit = l_visit
                    log_visitor(nip_clean, user_data[0])
                    st.rerun()
                else:
                    st.error("❌ NIP tidak ditemukan!")
    st.stop()

# ---------------------------
# Header & Navigasi
# ---------------------------
st.markdown(f"""
<div style='text-align:right; margin-bottom:10px; color:var(--muted);'>
    <div style='font-size:0.85rem;'>Halo, <b>{st.session_state.current_user_nama}</b> ({st.session_state.current_user_nip})</div>
    <div style='font-size:0.75rem; opacity:0.8; margin-top:2px;'>
        Kunjungan ke-{st.session_state.visit_count} | Terakhir: {st.session_state.last_visit}
    </div>
</div>
""", unsafe_allow_html=True)

st.markdown(f"<div class='header-center'><img src='{LOGO_PATH}' class='logo-img'/></div>", unsafe_allow_html=True)
st.markdown("""
<div class='header-center' style='margin-top: 12px;'>
  <div class='title-pill'>GMM RACEBOARD FASE 3</div>
  <div class='subtitle-small' style='margin-top: 8px;'>16 April 2026</div>
</div><br>
""", unsafe_allow_html=True)

# UBAH DISINI: Jadikan 5 kolom
k1, k2, k3, k4, k5 = st.columns(5)

if k1.button("🏠 HOME", width='stretch', type="primary" if st.session_state.kategori == "HOME" and st.session_state.view != "pencarian" else "secondary"): 
    st.session_state.kategori = "HOME"
    st.session_state.view = "home"  
    st.rerun()

if k2.button("📱 LIVIN", width='stretch', type="primary" if st.session_state.kategori == "LIVIN" and st.session_state.view != "pencarian" else "secondary"): 
    st.session_state.kategori = "LIVIN"
    if st.session_state.view in ["home", "pencarian"]:
        st.session_state.view = "cabang"
    st.rerun()

if k3.button("🏪 MERCHANT", width='stretch', type="primary" if st.session_state.kategori == "MERCHANT" and st.session_state.view != "pencarian" else "secondary"): 
    st.session_state.kategori = "MERCHANT"
    if st.session_state.view in ["home", "pencarian"]:
        st.session_state.view = "cabang"
    st.rerun()

if k4.button("💳 TRANSAKSI", width='stretch', type="primary" if st.session_state.kategori == "TRANSAKSI" and st.session_state.view != "pencarian" else "secondary"): 
    st.session_state.kategori = "TRANSAKSI"
    if st.session_state.view in ["home", "pencarian"]:
        st.session_state.view = "cabang"
    st.rerun()

# TAMBAHKAN DISINI: Tombol Cari Profil disejajarkan
if k5.button("🔍 CARI", width='stretch', type="primary" if st.session_state.view == "pencarian" else "secondary"):
    st.session_state.view = "pencarian"
    st.session_state.kategori = "HOME"
    st.rerun()

# ---------------------------
# Dynamic Action Menu (Admin vs User)
# ---------------------------
# 1. Tentukan kondisi kapan tombol Cabang & Pegawai harus disembunyikan
hide_leaderboard_btns = (
    st.session_state.kategori == "HOME" or 
    st.session_state.view == "pencarian" or 
    st.session_state.show_update_panel
)

# 2. Buat list tombol apa saja yang akan dimunculkan
menu_buttons = []

if not hide_leaderboard_btns:
    menu_buttons.extend(["cabang", "pegawai"])

# HAPUS BARIS INI: menu_buttons.append("search")

if getattr(st.session_state, "is_admin", False):
    menu_buttons.append("admin")

menu_buttons.append("logout")

# 3. Buat kolom sesuai jumlah tombol yang aktif (agar lebar proporsional)
columns = st.columns(len(menu_buttons))

# 4. Render tombol ke masing-masing kolom secara dinamis
for i, btn in enumerate(menu_buttons):
    col = columns[i]
    
    if btn == "cabang":
        btn_cabang_type = "primary" if st.session_state.view == "cabang" else "secondary"
        if col.button("🏢 Leaderboard Cabang", width='stretch', type=btn_cabang_type):
            st.session_state.view = "cabang"
            st.session_state.kode = None
            st.session_state.page_num = 1
            st.rerun()
            
    elif btn == "pegawai":
        btn_pegawai_type = "primary" if st.session_state.view == "pegawai" else "secondary"
        if col.button("👨‍💼 Leaderboard Pegawai", width='stretch', type=btn_pegawai_type):
            st.session_state.view = "pegawai"
            st.session_state.kode = "ALL"
            st.rerun()
            
    # HAPUS BLOK `elif btn == "search":` BESERTA ISINYA
            
    elif btn == "admin":
        btn_admin_type = "primary" if st.session_state.show_update_panel else "secondary"
        if col.button("⚙️ Admin Panel", width='stretch', type=btn_admin_type):
            st.session_state.show_update_panel = not st.session_state.show_update_panel
            st.rerun()
            
    elif btn == "logout":
        if col.button("🚪 Logout", help="Keluar/Logout", width='stretch'):
            st.session_state.clear()
            st.rerun()
# ---------------------------
# Admin Panel
# ---------------------------
if st.session_state.show_update_panel and st.session_state.get("is_admin", False):
    st.markdown("<hr style='border-color:rgba(255,255,255,0.04)'>", unsafe_allow_html=True)
    with st.expander("Admin Panel", expanded=True):
        # --- FITUR BARU: LOG AKSES & REKAP ---
        st.markdown("#### 📊 Rekapitulasi Pengunjung")
        conn = sqlite3.connect(DB_PATH)
        
        # Tabel Rekap (Siapa paling sering akses)
        df_summary = pd.read_sql_query("""
            SELECT 
                nip AS NIP, 
                nama AS Nama, 
                COUNT(*) AS 'Total Kunjungan', 
                MAX(waktu) AS 'Kunjungan Terakhir' 
            FROM access_log 
            GROUP BY nip, nama 
            ORDER BY 'Total Kunjungan' DESC
        """, conn)
        st.dataframe(df_summary, width='stretch', hide_index=True)
        
        # Tabel Log Mentah (100 log terakhir)
        st.markdown("#### 🕵️ 100 Riwayat Akses Terakhir")
        df_log = pd.read_sql_query("SELECT waktu, nip, nama, ip_address FROM access_log ORDER BY id DESC LIMIT 100", conn)
        st.dataframe(df_log, width='stretch', hide_index=True)
        
        conn.close()
        st.markdown("<hr>", unsafe_allow_html=True)

        upload_file = st.file_uploader("Upload Excel (.xlsx/.xls) - Berisi sheet GMM LIVIN, GMM MERCHANT, GMM TRANSAKSI", type=['xlsx','xls'])
        if upload_file:
            try:
                xls = pd.read_excel(upload_file, sheet_name=None, dtype=str)
                st.success(f"Berhasil membaca {len(xls)} sheet: {', '.join(xls.keys())}")
                
                if st.button("Mulai Import Semua Sheet"):
                    conn = sqlite3.connect(DB_PATH)
                    cur = conn.cursor()
                    
                    master_data = {}
                    
                    def find_col(df, aliases):
                        lc_cols = [str(c).lower().strip() for c in df.columns]
                        for a in aliases:
                            if a.lower() in lc_cols: return df.columns[lc_cols.index(a.lower())]
                        return None

                    # 1. Sheet LIVIN
                    if "GMM LIVIN" in xls:
                        df_l = xls["GMM LIVIN"]
                        c_nip = find_col(df_l, ['nip'])
                        c_nama = find_col(df_l, ['nama','employee name'])
                        c_kode = find_col(df_l, ['kode cabang','kode_cabang'])
                        if c_nip and c_nama:
                            for _, r in df_l.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                master_data[nip] = {
                                    'nip': nip, 'nama': str(r[c_nama]).strip(),
                                    'kode_cabang': str(r[c_kode]).strip() if c_kode else '',
                                    'unit': str(r[find_col(df_l, ['nama cabang','unit'])]).strip() if find_col(df_l, ['nama cabang','unit']) else '',
                                    'area': str(r[find_col(df_l, ['area','wilayah'])]).strip() if find_col(df_l, ['area','wilayah']) else '',
                                    'kelas_cabang': str(r[find_col(df_l, ['kelas cabang','kelas'])]).strip() if find_col(df_l, ['kelas cabang','kelas']) else '',
                                    'posisi': str(r[find_col(df_l, ['posisi','unit kerja'])]).strip() if find_col(df_l, ['posisi','unit kerja']) else '',
                                    'cif_akuisisi': normalize_val(r[find_col(df_l, ['cif akuisisi','cif'])]),
                                    'cif_setor': normalize_val(r[find_col(df_l, ['cif setor'])]),
                                    'end_balance': normalize_val(r[find_col(df_l, ['end_balance','end balance'])]),
                                    'rata_rata': normalize_val(r[find_col(df_l, ['rata-rata','rata rata'])]),
                                    'cif_sudah_transaksi': normalize_val(r[find_col(df_l, ['cif_sudah_transaksi','cif sudah transaksi'])]),
                                }

                    # 2. Sheet MERCHANT
                    if "GMM MERCHANT" in xls:
                        df_m = xls["GMM MERCHANT"]
                        c_nip = find_col(df_m, ['nip'])
                        if c_nip:
                            for _, r in df_m.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                if nip not in master_data:
                                    master_data[nip] = {'nip': nip, 'nama': str(r[find_col(df_m, ['nama pegawai','nama'])]).strip()}
                                master_data[nip]['total_referral_livin'] = normalize_val(r[find_col(df_m, ['total referral livin'])])
                                master_data[nip]['total_referral_edc'] = normalize_val(r[find_col(df_m, ['total referral edc'])])

                    # 3. Sheet TRANSAKSI
# 3. Sheet TRANSAKSI
                    if "GMM TRANSAKSI" in xls:
                        df_t = xls["GMM TRANSAKSI"]
                        c_nip = find_col(df_t, ['nip'])
                        if c_nip:
                            for _, r in df_t.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                if nip not in master_data:
                                    master_data[nip] = {'nip': nip, 'nama': str(r[find_col(df_t, ['nama pegawai','nama'])]).strip()}
                                master_data[nip]['total_poin_transaksi'] = normalize_val(r[find_col(df_t, ['total poin transaksi'])])
                                master_data[nip]['poin_on_us'] = normalize_val(r[find_col(df_t, ['poin on us'])])
                                master_data[nip]['poin_off_us'] = normalize_val(r[find_col(df_t, ['poin off us'])])
                                
                                # AMBIL DATA FREKUENSI SESUAI KOLOM BARU
                                master_data[nip]['frek_on_us'] = normalize_val(r[find_col(df_t, ['frek on us'])])
                                master_data[nip]['frek_off_us'] = normalize_val(r[find_col(df_t, ['frek off us'])])
                                master_data[nip]['pct_on_us'] = r[find_col(df_t, ['pct on us'])]

                    inserted = 0
                    for nip, d in master_data.items():
                        d.setdefault('kode_cabang', ''); d.setdefault('unit', ''); d.setdefault('area', ''); d.setdefault('kelas_cabang', '')
                        d.setdefault('end_balance', 0); d.setdefault('cif_akuisisi', 0); d.setdefault('cif_setor', 0)
                        d.setdefault('cif_sudah_transaksi', 0); d.setdefault('rata_rata', 0)
                        d.setdefault('total_referral_livin', 0); d.setdefault('total_referral_edc', 0)
                        d.setdefault('total_poin_transaksi', 0); d.setdefault('poin_on_us', 0); d.setdefault('poin_off_us', 0)
                        d.setdefault('frek_on_us', 0); d.setdefault('frek_off_us', 0); d.setdefault('pct_on_us', 0)

                        if d['kode_cabang']:
                            cur.execute("INSERT OR IGNORE INTO cabang (kode_cabang, unit, area, kelas_cabang) VALUES (?, ?, ?, ?)",
                                        (d['kode_cabang'], d.get('unit'), d.get('area'), d.get('kelas_cabang')))
                        
                        cur.execute("""
                            INSERT OR REPLACE INTO pegawai 
                            (nip, nama, kode_cabang, unit, area, posisi, end_balance, cif_akuisisi, cif_setor, cif_sudah_transaksi, rata_rata,
                             total_referral_livin, total_referral_edc, total_poin_transaksi, poin_on_us, poin_off_us, frek_on_us, frek_off_us, pct_on_us)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                        """, (d['nip'], d['nama'], d['kode_cabang'], d.get('unit'), d.get('area'), d.get('posisi',''), 
                              d['end_balance'], d['cif_akuisisi'], d['cif_setor'], d['cif_sudah_transaksi'], d['rata_rata'],
                              d['total_referral_livin'], d['total_referral_edc'], d['total_poin_transaksi'], d['poin_on_us'], d['poin_off_us'], d['frek_on_us'], d['frek_off_us'], d['pct_on_us']))
                        inserted += 1

                    conn.commit()
                    conn.close()
                    st.success(f"Import selesai! Berhasil update {inserted} data pegawai gabungan.")

            except Exception as e:
                st.error(f"Gagal memproses file: {e}")

        if st.button("⚠️ Hapus Seluruh Database"):
            conn = sqlite3.connect(DB_PATH)
            cur = conn.cursor()
            cur.execute("DELETE FROM pegawai")
            cur.execute("DELETE FROM cabang")
            conn.commit()
            conn.close()
            st.success("Database berhasil dikosongkan.")
        if st.button("Hapus Database"):
            conn = sqlite3.connect(DB_PATH)
            cur = conn.cursor()
            cur.execute("DROP TABLE pegawai")
            cur.execute("DROP TABLE cabang")
            conn.commit()
            conn.close()
            st.success("Database berhasil dikosongkan.")

kategori_aktif = st.session_state.kategori
if kategori_aktif != "HOME":
    fmt_fungsi = KAT_CONFIG[kategori_aktif]["fmt"]
    label_utama = KAT_CONFIG[kategori_aktif]["score_label"]
    label_kedua = KAT_CONFIG[kategori_aktif]["sec_label"]
            # --- PERBAIKAN LOGIKA SUMMARY KARTU ---




    # --- INJEKSI KHUSUS CABANG TRANSAKSI ---
    # Memastikan UI Cabang menampilkan Poin, bukan Persentase
    if st.session_state.view == "cabang" and kategori_aktif == "TRANSAKSI":
        fmt_fungsi = fmt_num
        label_utama = "Total Poin"
        label_kedua = "Poin On Us"


# ---------------------------
# FUNGSI RENDERER PROFIL (ADAPTIF)
# ---------------------------
def render_profil_pegawai(nip):
    conn = sqlite3.connect(DB_PATH)
    df_detail = pd.read_sql_query("SELECT * FROM pegawai WHERE nip = ?", conn, params=(nip,))
    
    if df_detail.empty: 
        st.error("Data pegawai tidak ditemukan.")
        conn.close()
        return False

    r = df_detail.iloc[0]
    
    def get_global_rank(col_name, score):
        cur = conn.cursor()
        cur.execute(f"SELECT COUNT(*) + 1 FROM pegawai WHERE {col_name} > ?", (score,))
        return cur.fetchone()[0]

    rank_livin = get_global_rank("end_balance", r["end_balance"])
    rank_merchant = get_global_rank("total_referral_edc", r["total_referral_edc"])
    rank_transaksi = get_global_rank("total_poin_transaksi", r["total_poin_transaksi"])
    conn.close()

    st.subheader(f"Profil Lengkap Pegawai")
    emp_banner = f"""
    <div class="emp-banner">
        <div class="emp-avatar">{r['nama'][0].upper() if r['nama'] else "?"}</div>
        <div>
            <div class="emp-info-title">{r['nama']}</div>
            <div style="opacity: 0.8; font-size: 0.85rem; margin-top: 4px;">{r['nip']}</div>
            <div style="opacity: 0.8; font-size: 0.85rem;">{r.get('posisi','')}</div>
            <div style="opacity: 0.8; font-size: 0.85rem;">{r.get('unit','')}</div>
        </div>
    </div>
    """
    st.markdown(emp_banner, unsafe_allow_html=True)

    st.markdown(f"<h4 style='color:var(--accent); margin-top:24px; font-size: 1.1rem;'>📱 LIVIN <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_livin}</span></h4>", unsafe_allow_html=True)
    cards_livin = [
        ("🏦", "End Balance", fmt_rp(r["end_balance"])),
        ("📌", "CIF Akuisisi", fmt_num(r["cif_akuisisi"])),
        ("💰", "CIF Setor", fmt_num(r["cif_setor"])), ("🔄", "CIF Transaksi", fmt_num(r["cif_sudah_transaksi"])),
        ("⏱️", "Frek Dari CIF", fmt_num(r["frek_dari_cif_akuisisi"])), ("📊", "Rata-rata", fmt_rp(r["rata_rata"]))
    ]
    html_livin = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_livin]) + "</div>"
    st.markdown(html_livin, unsafe_allow_html=True)

    st.markdown(f"<h4 style='color:var(--accent); margin-top:28px; font-size: 1.1rem;'>🏪 MERCHANT <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_merchant}</span></h4>", unsafe_allow_html=True)
    total_refferal = int(r.get("total_referral_edc",0)+ r.get("total_referral_livin",0))
    cards_merchant = [
        ("🏪", "Total Refferal",total_refferal ),
        ("🖥️", "Referral EDC", fmt_num(r["total_referral_edc"])),
        ("💳", "Referral LVM", fmt_num(r["total_referral_livin"]))]
    html_merchant = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_merchant]) + "</div>"
    st.markdown(html_merchant, unsafe_allow_html=True)

# Di dalam render_profil_pegawai (Sekitar baris 570)
    
    st.markdown(f"<h4 style='color:var(--accent); margin-top:28px; font-size: 1.1rem;'>💳 TRANSAKSI <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_transaksi}</span></h4>", unsafe_allow_html=True)

    poin_on_us = r.get("poin_on_us", 0)
    poin_off_us = r.get("poin_off_us", 0)
    
    # Ambil langsung dari data baru (tidak lagi dibagi 5)
    trx_on_us = r.get("frek_on_us", 0)
    trx_off_us = r.get("frek_off_us", 0)
    total_trx = trx_on_us + trx_off_us

    # Perhitungan Persentase (% On Us) yang lebih aman
    if total_trx > 0:
        pct_on_us = trx_on_us / total_trx
    else:
        pct_on_us = 0.0

    if pct_on_us < 0.80:
        kebutuhan_val = int((0.8 * total_trx - trx_on_us) / 0.2)
        kebutuhan_display = (
            f"{kebutuhan_val}"
            f"<span>Kamu perlu {kebutuhan_val} kali transaksi On Us agar mencapai 80%<br>"
            f"& Tanpa Menambah Transaksi Off Us</span>"
        )
    else:
        kebutuhan_display = (
            "Tercapai 🎉"
            "<span>Jaga agar selalu bertransaksi On Us</span>"
        )

    # Susun List Kartu
    cards_transaksi = [
        ("📈", "Total Poin", fmt_num(r.get("total_poin_transaksi", 0))),
        ("🏦", "Poin On Us", fmt_num(poin_on_us)),
        ("🌍", "Poin Off Us", fmt_num(poin_off_us)),
        ("📦", "Total Trx", fmt_num(int(total_trx))), 
        ("🔄", "Trx On Us", fmt_num(int(trx_on_us))),
        ("🌐", "Trx Off Us", fmt_num(int(trx_off_us))),
        ("📊", "% On Us", f"{pct_on_us:.1%}"),
        ("🎯", "% Target", "80.0%"),
        ("💡", "Kebutuhan", kebutuhan_display)  
    ]
    
    html_transaksi = "<div class='detail-grid'>" + "".join([
        f"<div class='detail-card{' highlight-card' if title == 'Kebutuhan' else ''}'>"
        f"<div class='detail-icon'>{icon}</div>"
        f"<div class='detail-title'>{title}</div>"
        f"<div class='detail-value'>{val}</div>"
        f"</div>" 
        for icon, title, val in cards_transaksi
    ]) + "</div>"
    st.markdown(html_transaksi, unsafe_allow_html=True)
    # html_transaksi = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_transaksi]) + "</div>"
    # st.markdown(html_transaksi, unsafe_allow_html=True)
    return True

def render_profil_cabang(kode_cabang):
    conn = sqlite3.connect(DB_PATH)
    query = """
        SELECT
            c.kode_cabang, c.unit, c.area, c.kelas_cabang,
            IFNULL(SUM(p.end_balance),0) as end_balance, IFNULL(SUM(p.cif_akuisisi),0) as cif_akuisisi,
            IFNULL(SUM(p.cif_setor),0) as cif_setor, IFNULL(SUM(p.cif_sudah_transaksi),0) as cif_sudah_transaksi,
            IFNULL(SUM(p.frek_dari_cif_akuisisi),0) as frek_dari_cif_akuisisi, IFNULL(AVG(p.rata_rata),0) as rata_rata,
            IFNULL(SUM(p.total_referral_livin),0) as total_referral_livin, IFNULL(SUM(p.total_referral_edc),0) as total_referral_edc,
            IFNULL(SUM(p.total_poin_transaksi),0) as total_poin_transaksi, IFNULL(SUM(p.poin_on_us),0) as poin_on_us,
            IFNULL(SUM(p.poin_off_us),0) as poin_off_us, COUNT(p.nip) as total_pegawai
        FROM cabang c LEFT JOIN pegawai p ON c.kode_cabang = p.kode_cabang
        WHERE c.kode_cabang = ? GROUP BY c.kode_cabang
    """
    df_detail = pd.read_sql_query(query, conn, params=(kode_cabang,))
    
    if df_detail.empty:
        st.error("Data cabang tidak ditemukan.")
        conn.close()
        return False

    r = df_detail.iloc[0]

    def get_cabang_rank(score_col, score_val):
        cur = conn.cursor()
        cur.execute(f"SELECT COUNT(*) + 1 FROM (SELECT SUM({score_col}) as val FROM pegawai GROUP BY kode_cabang) WHERE val > ?", (score_val,))
        return cur.fetchone()[0]

    rank_livin = get_cabang_rank("end_balance", r["end_balance"])
    rank_merchant = get_cabang_rank("total_referral_edc", r["total_referral_edc"])
    rank_transaksi = get_cabang_rank("total_poin_transaksi", r["total_poin_transaksi"])
    conn.close()

    st.subheader(f"Profil Lengkap Cabang")
    cabang_banner = f"""
    <div class="emp-banner" style="background: linear-gradient(135deg, #0f2027, #203a43, #2c5364);">
        <div class="emp-avatar" style="border-radius: 12px;">🏢</div>
        <div>
            <div class="emp-info-title">{r['unit']}</div>
            <div style="opacity: 0.8; font-size: 0.85rem; margin-top: 4px;">Kode: {r['kode_cabang']}</div>
            <div style="opacity: 0.8; font-size: 0.85rem;">Area: {r.get('area','-')} | Kelas: {r.get('kelas_cabang','-')}</div>
            <div style="opacity: 0.8; font-size: 0.85rem;">Total Pegawai: {r['total_pegawai']}</div>
        </div>
    </div>
    """
    st.markdown(cabang_banner, unsafe_allow_html=True)

    # --- TAMBAHAN: Tombol Detail Kategori ---
    col_l, col_m, col_t = st.columns(3)
    
    if col_l.button("📱 Detail LIVIN", width='stretch', key=f"detail_livin_{r['kode_cabang']}"):
        st.session_state.kategori = "LIVIN"
        st.session_state.view = "pegawai"
        st.session_state.kode = r['kode_cabang']
        st.rerun()

    if col_m.button("🏪 Detail MERCHANT", width='stretch', key=f"detail_merchant_{r['kode_cabang']}"):
        st.session_state.kategori = "MERCHANT"
        st.session_state.view = "pegawai"
        st.session_state.kode = r['kode_cabang']
        st.rerun()

    if col_t.button("💳 Detail TRANSAKSI", width='stretch', key=f"detail_transaksi_{r['kode_cabang']}"):
        st.session_state.kategori = "TRANSAKSI"
        st.session_state.view = "pegawai"
        st.session_state.kode = r['kode_cabang']
        st.rerun()
    # ----------------------------------------

    st.markdown(f"<h4 style='color:var(--accent); margin-top:24px; font-size: 1.1rem;'>📱 LIVIN <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_livin}</span></h4>", unsafe_allow_html=True)
    cards_livin = [
        ("🏦", "End Balance", fmt_rp(r["end_balance"])), ("📌", "CIF Akuisisi", fmt_num(r["cif_akuisisi"])),
        ("💰", "CIF Setor", fmt_num(r["cif_setor"])), ("🔄", "CIF Transaksi", fmt_num(r["cif_sudah_transaksi"])),
        ("⏱️", "Frek Dari CIF", fmt_num(r["frek_dari_cif_akuisisi"])), ("📊", "Rata-rata", fmt_rp(r["rata_rata"]))
    ]
    html_livin = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_livin]) + "</div>"
    st.markdown(html_livin, unsafe_allow_html=True)

    st.markdown(f"<h4 style='color:var(--accent); margin-top:28px; font-size: 1.1rem;'>🏪 MERCHANT <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_merchant}</span></h4>", unsafe_allow_html=True)
    cards_merchant = [("🏪", "Referral EDC", fmt_num(r["total_referral_edc"])), ("💳", "Referral LVM", fmt_num(r["total_referral_livin"]))]
    html_merchant = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_merchant]) + "</div>"
    st.markdown(html_merchant, unsafe_allow_html=True)

    st.markdown(f"<h4 style='color:var(--accent); margin-top:28px; font-size: 1.1rem;'>💳 TRANSAKSI <span style='color:white; font-size:0.85rem; background:rgba(255,255,255,0.1); padding:4px 10px; border-radius:12px; margin-left:8px; border: 1px solid rgba(255,255,255,0.2);'>🏆 Rank #{rank_transaksi}</span></h4>", unsafe_allow_html=True)
    cards_transaksi = [("📈", "Total Poin", fmt_num(r["total_poin_transaksi"])), ("🛡️", "Poin On Us", fmt_num(r["poin_on_us"])), ("🌐", "Poin Off Us", fmt_num(r["poin_off_us"]))]
    html_transaksi = "<div class='detail-grid'>" + "".join([f"<div class='detail-card'><div class='detail-icon'>{icon}</div><div class='detail-title'>{title}</div><div class='detail-value'>{val}</div></div>" for icon, title, val in cards_transaksi]) + "</div>"
    st.markdown(html_transaksi, unsafe_allow_html=True)
    return True


# View: Pencarian Profil Terpadu
if st.session_state.view == "pencarian":
    st.subheader("🔍 Pencarian Profil Cabang dan Pegawai")
    st.markdown("<p class='small-muted'>Cari profil spesifik berdasarkan Nama, NIP Pegawai, atau Nama Unit Cabang.</p>", unsafe_allow_html=True)

    # Mengambil list semua Pegawai dan Cabang
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    
    cur.execute("SELECT nip, nama FROM pegawai ORDER BY nama ASC")
    peg_list = [f"👤 {row[0]} - {row[1]}" for row in cur.fetchall()]
    
    # UBAH BARIS INI: Tambahkan filter WHERE pada query cabang
    cur.execute("""
        SELECT kode_cabang, unit FROM cabang 
        WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan') 
        ORDER BY unit ASC
    """)
    cab_list = [f"🏢 {row[0]} - {row[1]}" for row in cur.fetchall()]
    conn.close()

    # Menggabungkan list
    all_options = ["-- Ketik atau Pilih Disini --"] + cab_list + peg_list
    
    # Input pencarian adaptif
    selected_profile = st.selectbox("Cari Cabang / Pegawai:", options=all_options)

    if selected_profile != "-- Ketik atau Pilih Disini --":
        st.markdown("<hr style='border-color:rgba(255,255,255,0.05); margin-top:0;'>", unsafe_allow_html=True)
        
        # Mengecek apakah yang dipilih itu Cabang atau Pegawai dari Icon di depan string
        is_pegawai = selected_profile.startswith("👤")
        raw_text = selected_profile[2:]
        entity_id = raw_text.split(" - ")[0].strip()

        if is_pegawai:
            render_profil_pegawai(entity_id)
        else:
            render_profil_cabang(entity_id)


# ---------------------------
# View: HOME (Dashboard Top & Worst)
# ---------------------------
if st.session_state.kategori == "HOME" and st.session_state.view not in ["detail_pegawai","leaderboard_cabang","leaderboard_pegawai"]:
    st.subheader("🏠 Dashboard Summary")
    st.markdown("<p class='small-muted' style='margin-bottom: 16px;'>Top 3 dan Bottom 3 performa Cabang & Pegawai di semua kategori.</p>", unsafe_allow_html=True)
    
    kats = ["LIVIN", "MERCHANT", "TRANSAKSI"]
    
    def render_mini_list(title, df_list, name_col, sub_col, score_col, fmt_fn, is_worst=False):
        badge_bg = "linear-gradient(135deg, #ff4b4b, #b30000)" if is_worst else "linear-gradient(135deg,#26c57a,#00a060)"
        icon = "📉" if is_worst else "🏆"
        html = f"<div style='margin-bottom: 20px; background: rgba(255,255,255,0.02); padding: 14px; border-radius: 16px; border: 1px solid rgba(255,255,255,0.04); box-shadow: 0 4px 12px rgba(0,0,0,0.2);'>"
        html += f"<h5 style='margin-bottom:12px; font-size: 1rem; color: #fff;'>{icon} {title}</h5>"
        
        if df_list.empty:
            return html + "<div class='small-muted'>Data belum tersedia.</div></div>"
            
        for idx, r in enumerate(df_list.to_dict('records')):
            val = fmt_fn(r[score_col])
            name = r[name_col]
            sub = r.get(sub_col, '-')
            
            html += f"<div class='row-card' style='padding: 10px; margin-bottom: 8px; border-radius: 10px;'>"
            html += f"<div class='row-left'>"
            html += f"<div class='rank-badge' style='background:{badge_bg}; color:white; width:34px; height:34px; font-size:14px;'>{idx+1}</div>"
            html += f"<div class='row-meta'>"
            html += f"<div class='unit' style='font-size:0.9rem;'>{name}</div>"
            html += f"<div class='small-muted' style='font-size:0.75rem;'>{sub}</div>"
            html += f"</div></div>"
            html += f"<div class='row-right' style='font-size:0.9rem; color:var(--accent);'>{val}</div>"
            html += f"</div>"
            
        html += "</div>"
        return html

    for kat in kats:
        st.markdown(f"<h3 style='color: var(--accent); margin-top: 30px;'>📊 KATEGORI {kat}</h3>", unsafe_allow_html=True)
        df_c = get_cabang_leaderboard(kat)
        df_p = get_pegawai("ALL", kat)
        
        # Pisahkan formatter karena TRANSAKSI cabang dan pegawai beda metrik
        fmt_fn_p = KAT_CONFIG[kat]["fmt"]
        fmt_fn_c = fmt_num if kat == "TRANSAKSI" else KAT_CONFIG[kat]["fmt"]
        
        df_c_active = df_c
        df_p_active = df_p
        
        if not df_c_active.empty:
            df_c_bot_filtered = df_c_active[df_c_active['kelas_cabang'] != "A/R"]
        else:
            df_c_bot_filtered = df_c_active

        top_c = df_c.head(3)
        bot_c = df_c_bot_filtered.tail(3).iloc[::-1] if not df_c_bot_filtered.empty else df_c_bot_filtered.head(0)
        top_p = df_p.head(3)
        bot_p = df_p_active.tail(3).iloc[::-1] if not df_p_active.empty else df_p.tail(3).iloc[::-1]
        
        c1, c2 = st.columns(2)
        # Gunakan fmt_fn_c untuk Cabang
        with c1: st.markdown(render_mini_list(f"Top 3 Cabang {kat}", top_c, "unit", "area", "total_balance", fmt_fn_c, False), unsafe_allow_html=True)
        with c2: st.markdown(render_mini_list(f"Bot 3 Cabang {kat}", bot_c, "unit", "area", "total_balance", fmt_fn_c, True), unsafe_allow_html=True)
            
        p1, p2 = st.columns(2)
        # Gunakan fmt_fn_p untuk Pegawai
        with p1: st.markdown(render_mini_list(f"Top 3 Pegawai {kat}", top_p, "nama", "unit", "end_balance", fmt_fn_p, False), unsafe_allow_html=True)
        with p2: st.markdown(render_mini_list(f"Bot 3 Pegawai {kat}", bot_p, "nama", "unit", "end_balance", fmt_fn_p, True), unsafe_allow_html=True)
        st.markdown("<hr style='border-color: rgba(255,255,255,0.06); margin: 10px 0;'>", unsafe_allow_html=True)

# ---------------------------
# View: Cabang
# ---------------------------
if st.session_state.view == "cabang":
    df = get_cabang_leaderboard(kategori_aktif)

    area_options = ["All Area"] + sorted(df['area'].dropna().unique())
    kelas_options = ["All Kelas"] + sorted(df['kelas_cabang'].dropna().unique())

    colA, colB = st.columns(2)
    with colA: area_filter = st.selectbox("Filter Area", options=area_options)
    with colB: kelas_filter = st.selectbox("Filter Kelas Cabang", options=kelas_options)

    if area_filter != "All Area": df = df[df['area'] == area_filter]
    if kelas_filter != "All Kelas": df = df[df['kelas_cabang'] == kelas_filter]

    total_all = df['total_balance'].sum() if not df.empty else 0
    st.subheader(f"🏆 Top 3 Cabang - {kategori_aktif}")
    
    # ---------------------------
    # MODIFIKASI: Tombol Streamlit pada Top 3 Cabang
    # ---------------------------
    for i in range(min(3, len(df))):
        r = df.iloc[i]
        share_pct = (r['total_balance'] / total_all * 100) if total_all > 0 else 0
        medals = [("Rank 1", "🥇", "gold"), ("Rank 2", "🥈", "silver"), ("Rank 3", "🥉", "bronze")]
        label, emoji, cls = medals[i]
        
        st.markdown(f"""
        <div class='leaderboard-card' style="margin-bottom: 4px; border-bottom-left-radius: 0; border-bottom-right-radius: 0;">
            <div style='display:flex;justify-content:space-between;align-items:flex-start;'>
            <div>
                <div class='medal {cls}'>{emoji} {label}</div>
                <div style='font-weight:800; font-size:1.1rem;'>{r['unit']}</div>
                <div class='small-muted'>{r['area']}</div>
            </div>
            <div style='text-align:right'>
                <div style='font-weight:800; font-size:1.2rem; color:var(--accent);'>{fmt_fungsi(r['total_balance'])}</div>
                <div class='small-muted'>{r['kode_cabang']}</div>
            </div>
            </div>
        </div>
        """, unsafe_allow_html=True)
        
        # --- POSISI TOMBOL DI KANAN ---
        col1, col2 = st.columns([3, 1])
        with col1:
            st.markdown(f"<div class='small-muted' style='padding-left:12px;'>{label_kedua}: <span style='color:white;font-weight:bold;'>{fmt_num(r['total_cif'])}</span></div>", unsafe_allow_html=True)
        with col2:
            if st.button(f"Detail ➔", key=f"btn_top_{r['kode_cabang']}", width='stretch'):
                st.session_state.view = "pegawai"
                st.session_state.kode = r["kode_cabang"]
                st.rerun()
        st.markdown("<hr style='border-color: rgba(255,255,255,0.05); margin: 8px 0px 16px 0px;'>", unsafe_allow_html=True)

    st.markdown("<br><h4 style='font-size:1.1rem;'>Daftar Semua Cabang</h4>", unsafe_allow_html=True)

    # ---------------------------
    # MODIFIKASI: Tombol Streamlit pada Semua Cabang
    # ---------------------------
    for rank, (_, r) in enumerate(df.iterrows(), start=1):
        kode_cb = r.get('kode_cabang', '')
        unit = r.get('unit', '')
        area = r.get('area', '')
        total_balance = r.get('total_balance', 0)
        total_cif = r.get('total_cif', 0)
        
        rank_cls, medal, rank_label = ("", "", str(rank))
        if rank == 1: rank_cls, medal, rank_label = ("top1", "🥇", "1")
        elif rank == 2: rank_cls, medal, rank_label = ("top2", "🥈", "2")
        elif rank == 3: rank_cls, medal, rank_label = ("top3", "🥉", "3")

        # --- MENGGUNAKAN KOLOM UNTUK KIRI & KANAN ---
        col1, col2 = st.columns([3, 1])
        with col1:
            row_left = f"""
            <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                <div class="rank-badge {rank_cls}">{medal} {rank_label}</div>
                <div class="row-meta">
                    <div class="unit">{unit}</div>
                    <div class="info small-muted">Area: {area} • Kode: {kode_cb}</div>
                    <div class="info small-muted">{label_utama}: <span style="color:white;font-weight:bold;">{fmt_fungsi(total_balance)}</span>&nbsp;|&nbsp;{label_kedua}:<span style="color:white;font-weight:bold;">{fmt_fungsi(total_cif)}</span></div>
                </div>
            </div>
            """
            st.markdown(row_left, unsafe_allow_html=True)
            
        with col2:
            # Spacer agar tombol sejajar dengan tengah teks
            st.markdown("<div style='height: 8px;'></div>", unsafe_allow_html=True)
            if st.button("Lihat ➔", key=f"btn_cb_{kode_cb}_{rank}", width='stretch'):
                st.session_state.view = "pegawai"
                st.session_state.kode = kode_cb
                st.rerun()
                
        st.markdown("<hr style='border-color: rgba(255,255,255,0.05); margin: 4px 0px 12px 0px;'>", unsafe_allow_html=True)


# ---------------------------
# View: Pegawai & Futsal Pitch
# ---------------------------
def render_futsal_responsive(players, fmt_fn):
    import html as _html
    pl = list(players) if players is not None else []
    def esc(x, default='-'):
        try: return _html.escape(str(x))
        except: return default
    def name_of(p): return esc(p.get("nama")) if p else "-"
    def bal_of(p): return fmt_fn(p.get("end_balance", 0)) if p else fmt_fn(0)

    def point_html(idx, p):
        name = name_of(p)
        posisi = esc(p.get("posisi")) if p else "-"
        bal = bal_of(p)
        return f'''
        <div class="pt-wrap" title="{name} · {bal}">
          <div class="pt-dot">{idx}</div>
          <div class="pt-label">
            <div class="pl-name">{name}</div>
            <div class="pl-posisi">({posisi})</div>
            <div class="pl-bal">{bal}</div>
          </div>
        </div>
        '''

    field = [pl[i] if i < len(pl) else None for i in range(5)]
    bench = pl[5:] if len(pl) > 5 else []

    template = r'''
    <div style="width:100%;display:flex;justify-content:center;padding:10px 0;">
      <div style="width:100%;max-width:1100px;">
        <style>
          :root{box-sizing:border-box} *{box-sizing:inherit}
          .pitch{ position:relative; background: linear-gradient(180deg,#053a31,#042822); border-radius:12px; padding:12px; border:1px solid rgba(255,255,255,0.03); box-shadow: inset 0 30px 60px rgba(0,0,0,0.32); overflow:hidden; color:rgba(255,255,255,0.95); }
          .court-svg{ position:absolute; inset:0; width:100%; height:100%; pointer-events:none; opacity:0.14; }
          .field-grid{ display:grid; grid-template-columns: minmax(10px,1fr) repeat(3, minmax(80px, 160px)) minmax(10px,1fr); grid-template-rows: min-content 1fr 1fr; gap:8px 10px; align-items:end; justify-items:center; min-height:300px; width:100%; }
          .p1{ grid-column: 2 / 5; grid-row: 1 / 2; }
          .p2{ grid-column: 2 / 3; grid-row: 2 / 3; }
          .p3{ grid-column: 4 / 5; grid-row: 2 / 3; }
          .p4{ grid-column: 1 / 2; grid-row: 3 / 4; }
          .p5{ grid-column: 5 / 6; grid-row: 3 / 4; }
          .pt-wrap{ display:flex; flex-direction:column; align-items:center; gap:4px; width:100%; max-width:120px; padding:4px; }
          .pt-dot{ width:44px; height:44px; border-radius:50%; background: radial-gradient(circle at 30% 30%, #26c57a, #00a060); display:flex; align-items:center; justify-content:center; font-weight:900; color:#052c20; box-shadow:0 8px 18px rgba(0,0,0,0.45); border:2px solid rgba(255,255,255,0.06); font-size:14px; }
          .pt-label{ text-align:center; } .pl-name{ font-weight:800; font-size:11px; line-height:1.05; overflow:hidden; text-overflow:ellipsis;} .pl-posisi{ font-size:8px; margin-top:2px; color:rgba(255,255,255,0.8); } .pl-bal{ font-weight:700; font-size:11px; margin-top:2px; color:rgba(255,255,255,0.9); }
          .separator{ margin-top:12px; border-top:2px dashed rgba(255,255,255,0.05); padding-top:12px; } .bench{ display:flex; flex-wrap:wrap; gap:8px; justify-content:center; align-items:flex-start; }
          @media (max-width:500px){ .field-grid{ grid-template-columns: minmax(2px,1fr) repeat(3, minmax(50px, 100px)) minmax(2px,1fr); gap:6px 4px; min-height:280px; } .pt-wrap{ max-width:80px; } .pt-dot{ width:36px; height:36px; font-size:12px; } .pl-name, .pl-bal{ font-size:10px } }
        </style>
        <div class="pitch">
          <svg class="court-svg" viewBox="0 0 1000 600" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="40" y="30" width="920" height="540" rx="18" ry="18" fill="none" stroke="white" stroke-width="4" opacity="0.08"/>
            <line x1="500" y1="30" x2="500" y2="570" stroke="white" stroke-width="2" opacity="0.06" />
            <circle cx="500" cy="300" r="60" fill="none" stroke="white" stroke-width="2" opacity="0.06" />
          </svg>
          <div class="field-grid">
            <div class="p1">__P1__</div><div class="p2">__P2__</div><div class="p3">__P3__</div><div class="p4">__P4__</div><div class="p5">__P5__</div>
          </div>
          <div class="separator"><div class="bench">__BENCH__</div></div>
        </div>
      </div>
    </div>
    '''

    pieces = {'__P1__': point_html(1, field[0]), '__P2__': point_html(2, field[1]), '__P3__': point_html(3, field[2]), '__P4__': point_html(4, field[3]), '__P5__': point_html(5, field[4])}
    bench_html = ""
    for idx, b in enumerate(bench, start=6): bench_html += point_html(idx, b)
    if not bench: bench_html = '<div style="color:rgba(255,255,255,0.6);font-size:13px;padding:8px">Bench kosong</div>'

    html_out = template
    for k, v in pieces.items(): html_out = html_out.replace(k, v)
    return html_out.replace('__BENCH__', bench_html)

if st.session_state.view == "pegawai":
    kode = st.session_state.kode or "ALL"
    st.subheader(f"Leaderboard Pegawai - {kategori_aktif}")
    
    dfc = get_cabang_leaderboard(kategori_aktif)
    options = ["ALL"] + dfc['area'].dropna().unique().tolist() + dfc.apply(lambda r: f"{r['kode_cabang']} — {r['unit']}", axis=1).tolist()
    
    default_index = 0
    if st.session_state.kode in dfc['area'].values: default_index = options.index(st.session_state.kode)
    else:
        for r in dfc.itertuples():
            if st.session_state.kode == r.kode_cabang:
                cb_lbl = f"{r.kode_cabang} — {r.unit}"
                if cb_lbl in options: default_index = options.index(cb_lbl)
                break

    chosen = st.selectbox("Ketik Disini (Cari Area/Cabang)", options=options, index=default_index)
    if chosen == "ALL": st.session_state.kode = "ALL"
    elif " — " in chosen: st.session_state.kode = chosen.split(" — ",1)[0].strip()
    else: st.session_state.kode = chosen

    dfp_all = get_pegawai(st.session_state.kode, kategori_aktif)

    
    if dfp_all.empty:
        st.warning("Tidak ada pegawai untuk filter ini.")
    else:
        total_pegawai = len(dfp_all)

        # --- PERBAIKAN LOGIKA SUMMARY KARTU ---
        if kategori_aktif == "TRANSAKSI":
            # Khusus Transaksi: Tampilkan total poin murni, bukan jumlah persentase
            val_akumulasi = dfp_all["total_poin_transaksi"].sum()
            label_akumulasi = "Total Poin"
            fmt_akumulasi = fmt_num  # Gunakan format angka biasa (bukan %)
        else:
            # Kategori Lainnya (Livin, Merchant): Tetap normal
            val_akumulasi = dfp_all["end_balance"].sum()
            label_akumulasi = f"Akumulasi {label_utama}"
            fmt_akumulasi = fmt_fungsi

        avg_balance = dfp_all["end_balance"].mean()
        total_cif = dfp_all["cif_akuisisi"].sum()
        
        top_row = dfp_all.iloc[0]
        top_name = top_row["nama"]
        top_value = top_row["end_balance"]

        st.markdown(f"""
        <div class="stat-container">
            <div class="stat-card">
                <div class="stat-title">Total Pegawai</div>
                <div class="stat-value">{total_pegawai}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">{label_akumulasi}</div>
                <div class="stat-value" style="color:var(--accent);">{fmt_akumulasi(val_akumulasi)}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Rata-rata {label_utama}</div>
                <div class="stat-value">{fmt_fungsi(avg_balance)}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Top Performer</div>
                <div class="stat-value">{top_name}</div>
                <div class="stat-extra">{fmt_fungsi(top_value)}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Total {label_kedua}</div>
                <div class="stat-value">{fmt_num(total_cif)}</div>
            </div>
        </div>
        """, unsafe_allow_html=True)

        players = dfp_all.to_dict('records')
        html_pitch = render_futsal_responsive(players, fmt_fungsi)
        import streamlit.components.v1 as components
        components.html(html_pitch, height=650, scrolling=True)

        st.markdown("---")
        st.markdown("<h4 style='font-size:1.1rem;'>Daftar Pegawai</h4>", unsafe_allow_html=True)
        
        page_size = 50
        total_pages = max(1, math.ceil(total_pegawai / page_size))
        start = (st.session_state.page_num - 1) * page_size
        end = start + page_size
        dfp_page = dfp_all.iloc[start:end]

        # ---------------------------
        # MODIFIKASI: Tombol Streamlit pada Daftar Pegawai
        # ---------------------------
        for idx, (_, r) in enumerate(dfp_page.iterrows(), start=start+1):
            nama, nip, posisi = r.get('nama', '-'), r.get('nip', ''), r.get('posisi','')
            cif_display = fmt_num(r.get('cif_akuisisi', 0))
            end_bal = fmt_fungsi(r.get('end_balance', 0))
            
            rank_cls, medal, rank_label = ("", "", str(idx))
            if idx == 1: rank_cls, medal, rank_label = ("top1", "🥇", "1")
            elif idx == 2: rank_cls, medal, rank_label = ("top2", "🥈", "2")
            elif idx == 3: rank_cls, medal, rank_label = ("top3", "🥉", "3")

            # --- MENGGUNAKAN KOLOM UNTUK KIRI & KANAN ---
            col1, col2 = st.columns([3, 1])
            with col1:
                row_left = f"""
                <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 4px;">
                    <div class="rank-badge {rank_cls}">{medal} {rank_label}</div>
                    <div class="row-meta">
                        <div class="name">{nama}</div>
                        <div class="small-muted">{nip} · {posisi}</div>
                                            <div class="info small-muted">{label_utama}: <span style="color:white;font-weight:bold;">{end_bal}</span>&nbsp;|&nbsp;{label_kedua}:<span style="color:white;font-weight:bold;">{cif_display}</span></div>
                    </div>
                </div>
                """
                st.markdown(row_left, unsafe_allow_html=True)
                
            with col2:
                # Spacer agar tombol sejajar dengan tengah teks
                st.markdown("<div style='height: 8px;'></div>", unsafe_allow_html=True)
                if st.button("Detail ➔", key=f"btn_peg_{nip}_{idx}", width='stretch'):
                    st.session_state.view = "detail_pegawai"
                    st.session_state.detail_nip = nip 
                    st.rerun()
                    
            st.markdown("<hr style='border-color: rgba(255,255,255,0.05); margin: 4px 0px 12px 0px;'>", unsafe_allow_html=True)

        b1, b2, b3 = st.columns([1,2,1])

        if b1.button("⬅️", width='stretch'):
            if st.session_state.page_num > 1:
                st.session_state.page_num -= 1
            else:
                st.session_state.page_num = total_pages  # lompat ke terakhir
            st.rerun()

        b2.markdown(
            f"<div style='text-align:center; padding-top:8px; font-weight:bold; font-size:0.9rem;'>Hal {st.session_state.page_num} dari {total_pages}</div>",
            unsafe_allow_html=True
        )

        if b3.button("➡️", width='stretch'):
            if st.session_state.page_num < total_pages:
                st.session_state.page_num += 1
            else:
                st.session_state.page_num = 1  # balik ke awal
            st.rerun()


# ---------------------------
# View: Detail Pegawai (Dari Leaderboard)
# ---------------------------
if st.session_state.view == "detail_pegawai":
    nip = st.session_state.get("detail_nip")
    if not nip: 
        st.error("NIP tidak ditemukan. Silakan kembali ke halaman sebelumnya.")
    else:
        render_profil_pegawai(nip)
        
        st.markdown("<br><hr style='border-color:rgba(255,255,255,0.05);'>", unsafe_allow_html=True)
        if st.button("⬅️ Kembali ke Daftar Pegawai", width='stretch'):
            st.session_state.view = "pegawai"
            st.rerun()
