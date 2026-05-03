import sqlite3
import pandas as pd
import streamlit as st
import io
import os
import math
import re
from datetime import datetime
from zoneinfo import ZoneInfo

# ---------------------------
# 1. KONFIGURASI HALAMAN
# ---------------------------
st.set_page_config(
    page_title="GMM RACEBOARD F1", 
    page_icon="🏆", 
    layout="wide", 
    initial_sidebar_state="collapsed"
)

DB_PATH = "ycc_leaderboard.db"

# ---------------------------
# 2. FORMATTERS & CONFIG KPI
# ---------------------------
def fmt_rp(value):
    try:
        v = int(round(float(value)))
        return f"Rp {v:,}".replace(",", ".") + " Jt"
    except:
        return "Rp 0 Jt"

def fmt_num(value):
    try:
        v = int(round(float(value)))
        return f"{v:,}".replace(",", ".")
    except:
        return "0"

def fmt_pct(value):
    try:
        v = float(value)
        return f"{v * 100:.1f}%" if v <= 1 else f"{v:.1f}%"
    except:
        return "0.0%"

def fmt_growth(current, base, formatter, is_penalty=False):
    """Menghitung dan memformat indikator pertumbuhan (Growth DtD) dengan gaya Badge"""
    try:
        curr_val = float(current) if current else 0.0
        base_val = float(base) if base else 0.0
        
        if is_penalty:
            curr_val = abs(curr_val)
            base_val = abs(base_val)
            
        diff = curr_val - base_val
        
        if diff > 0:
            color = "#E10600" if is_penalty else "#10B981" 
            bg_color = "#FFF5F5" if is_penalty else "#ECFDF5"
            arrow, sign = "▲", "+"
        elif diff < 0:
            color = "#10B981" if is_penalty else "#E10600" 
            bg_color = "#ECFDF5" if is_penalty else "#FFF5F5"
            arrow, sign = "▼", "-"
            diff = abs(diff) 
        else:
            return "<span style='background:#F1F5F9; color:#64748B; padding:4px 8px; border-radius:6px; font-weight:700; font-size:0.8rem; border:1px solid #CBD5E1;'>➖ 0</span>"
        
        diff_str = formatter(diff)
        return f"<span style='background:{bg_color}; color:{color}; padding:4px 8px; border-radius:6px; font-weight:800; font-size:0.8rem; letter-spacing:-0.5px; border:1px solid {color}50; white-space:nowrap; box-shadow: 0 1px 2px rgba(0,0,0,0.1);'>{arrow} {sign}{diff_str}</span>"
    except Exception as e:
        return "<span style='background:#F1F5F9; color:#64748B; padding:4px 8px; border-radius:6px; font-weight:700; font-size:0.8rem;'>➖ N/A</span>"
KAT_CONFIG = {
    "LIVIN": {
        "score_col": "end_balance", "score_label": "End Balance",
        "sec_col": "cif_akuisisi", "sec_label": "CIF Akuisisi", "fmt": fmt_rp
    },
    "MERCHANT": {
        "score_col": "total_referral_edc", "score_label": "Referral EDC",
        "sec_col": "total_referral_livin", "sec_label": "Referral LVM", "fmt": fmt_num
    },
    "TRANSAKSI": {
        "score_col": "pct_on_us", "score_label": "% On Us",
        "sec_col": "total_poin_transaksi", "sec_label": "Total Poin", "fmt": fmt_pct 
    }
}

# ---------------------------
# 3. DATABASE SETUP
# ---------------------------
def init_db():
    conn = sqlite3.connect(DB_PATH, timeout=15.0)
    conn.execute("PRAGMA journal_mode=WAL;")
    cur = conn.cursor()

    # --- CREATE TABLE ---
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
            area TEXT,
            nama_cabang TEXT,
            posisi TEXT,
            avatar_url TEXT,
            end_balance REAL DEFAULT 0,
            cif_akuisisi REAL DEFAULT 0,
            cif_setor REAL DEFAULT 0,
            cif_sudah_transaksi REAL DEFAULT 0,
            frek_dari_cif_akuisisi REAL DEFAULT 0,
            rata_rata REAL DEFAULT 0,
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

    # --- INDEX (WAJIB untuk performa) ---
    try:
        cur.execute("CREATE INDEX IF NOT EXISTS idx_pegawai_kode_cabang ON pegawai(kode_cabang)")
        cur.execute("CREATE INDEX IF NOT EXISTS idx_pegawai_area ON pegawai(area)")
        cur.execute("CREATE INDEX IF NOT EXISTS idx_pegawai_nip ON pegawai(nip)")
        cur.execute("CREATE INDEX IF NOT EXISTS idx_pegawai_is_active ON pegawai(is_active)")
    except:
        pass

    # --- TAMBAH KOLOM BASE ---
    base_cols = [
        "end_balance_base", "cif_akuisisi_base", "cif_setor_base",
        "cif_sudah_transaksi_base", "frek_dari_cif_akuisisi_base",
        "rata_rata_base", "total_referral_livin_base",
        "total_referral_edc_base", "total_poin_transaksi_base",
        "poin_on_us_base", "poin_off_us_base",
        "frek_on_us_base", "frek_off_us_base", "pct_on_us_base"
    ]

    for col in base_cols:
        try:
            cur.execute(f"ALTER TABLE pegawai ADD COLUMN {col} REAL DEFAULT 0")
        except:
            pass

    # --- KOLOM ACTIVE ---
    try:
        cur.execute("ALTER TABLE pegawai ADD COLUMN is_active INTEGER DEFAULT 1")
    except:
        pass

    # --- ACCESS LOG ---
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

@st.cache_data(ttl=60)
def get_cabang_leaderboard(kategori="LIVIN"):
    conf = KAT_CONFIG[kategori]
    sc, se = conf["score_col"], conf["sec_col"]

    if kategori == "TRANSAKSI":
        sc_expr, se_expr = "SUM(p.total_poin_transaksi)", "SUM(p.poin_on_us)"
        sc_base_expr, se_base_expr = "SUM(p.total_poin_transaksi_base)", "SUM(p.poin_on_us_base)"
    else:
        sc_expr, se_expr = f"SUM(p.{sc})", f"SUM(p.{se})"
        sc_base_expr, se_base_expr = f"SUM(p.{sc}_base)", f"SUM(p.{se}_base)"

    conn = sqlite3.connect(DB_PATH)
    df = pd.read_sql_query(f"""
        SELECT k.kode_cabang, COALESCE(c.unit, k.kode_cabang) AS unit, COALESCE(c.area, '(Unknown)') AS area, COALESCE(c.kelas_cabang, '-') AS kelas_cabang,
               IFNULL({sc_expr},0) AS total_balance, IFNULL({sc_base_expr},0) AS total_balance_base,
               (IFNULL({sc_expr},0) - IFNULL({sc_base_expr},0)) AS growth_score,
               IFNULL({se_expr},0) AS total_cif, IFNULL({se_base_expr},0) AS total_cif_base,
               (IFNULL({se_expr},0) - IFNULL({se_base_expr},0)) AS growth_cif,
               IFNULL(COUNT(p.nip),0) AS jumlah_pegawai
        FROM (SELECT kode_cabang FROM cabang WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan','aktif')
              UNION SELECT DISTINCT kode_cabang FROM pegawai WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan','aktif')) k
        LEFT JOIN cabang c ON k.kode_cabang = c.kode_cabang
        LEFT JOIN pegawai p ON k.kode_cabang = p.kode_cabang AND p.is_active = 1
        GROUP BY k.kode_cabang ORDER BY total_balance DESC
    """, conn)
    conn.close()
    if not df.empty:
        df['rank_default'] = df['total_balance'].rank(method='min', ascending=False).astype(int)
    return df
@st.cache_data(ttl=60)
def get_pegawai(kode, kategori="LIVIN"):
    conf = KAT_CONFIG[kategori]
    sc, se = conf["score_col"], conf["sec_col"]

    if kategori == "TRANSAKSI":
        sc_expr = "(CASE WHEN (frek_on_us + frek_off_us) > 0 THEN (frek_on_us / (frek_on_us + frek_off_us)) ELSE 0 END)"
        se_expr, sc_base_expr = "total_poin_transaksi", "pct_on_us_base"
        se_base_expr = "total_poin_transaksi_base"
    else:
        sc_expr, se_expr, sc_base_expr = sc, se, f"{sc}_base"
        se_base_expr = f"{se}_base"

    conn = sqlite3.connect(DB_PATH)
    base_query = f"""
        SELECT *, IFNULL({sc_expr},0) AS score_utama, IFNULL({sc_base_expr},0) AS score_utama_base,
               (IFNULL({sc_expr},0) - IFNULL({sc_base_expr},0)) AS growth_score, 
               IFNULL({se_expr},0) AS score_kedua, IFNULL({se_base_expr},0) AS score_kedua_base,
               (IFNULL({se_expr},0) - IFNULL({se_base_expr},0)) AS growth_kedua
        FROM pegawai WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan','aktif') AND is_active = 1
    """
    
    if kode is None or kode == "ALL": df = pd.read_sql_query(base_query + " ORDER BY score_utama DESC, score_kedua DESC", conn)
    elif len(kode) == 3: df = pd.read_sql_query(base_query + " AND area = ? ORDER BY score_utama DESC, score_kedua DESC", conn, params=(kode,))
    else:
        df_cabang = pd.read_sql_query("SELECT kode_cabang FROM cabang", conn)
        if kode in df_cabang['kode_cabang'].tolist(): df = pd.read_sql_query(base_query + " AND kode_cabang = ? ORDER BY score_utama DESC, score_kedua DESC", conn, params=(kode,))
        else: df = pd.read_sql_query(base_query + " AND area = ? ORDER BY score_utama DESC, score_kedua DESC", conn, params=(kode,))
    conn.close()
    
    if not df.empty:
        df['rank_default'] = df['score_utama'].rank(method='min', ascending=False).astype(int)
    return df

def normalize_val(x):
    if pd.isna(x) or x is None: return 0
    s = str(x).strip().replace(',', '.')
    s = re.sub(r'[^\d\.-]', '', s)
    try: return float(s)
    except: return 0

# ---------------------------
# 4. CSS STYLING F1 WHITE EDITION (V14 - PREMIUM POLISH)
# ---------------------------
LOGO_PATH = "https://github.com/Cyberius8/EDA-Mandiri/blob/main/R11GMM.jpg?raw=true"
ENHANCED_CSS = rf"""
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap');

:root {{ 
    --f1-red: #E10600; 
    --f1-dark: #15151E;
    --text-dark: #1E293B; 
    --text-muted: #64748B; 
    --text-light: #94A3B8;
    --border: #E2E8F0; 
    --bg-card: #FFFFFF; 
    --bg-app: #F8FAFC; 
    --shadow-sm: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
    --shadow-md: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
    --shadow-hover: 0 10px 15px -3px rgba(0, 0, 0, 0.08), 0 4px 6px -2px rgba(0, 0, 0, 0.04);
}}

/* Global Reset & Background */
.stApp {{ background-color: var(--bg-app) !important; font-family: 'Inter', sans-serif; color: var(--text-dark) !important; }}
#MainMenu {{visibility: hidden;}} header {{visibility: hidden;}} footer {{visibility: hidden;}}
.block-container {{ padding-top: 1.5rem !important; padding-bottom: 2rem !important; padding-left: 1rem !important; padding-right: 1rem !important; max-width: 1280px; overflow-x: hidden; }}

/* --- STYLING STREAMLIT NATIVE BUTTONS --- */
div[data-testid="stButton"] > button[kind="secondary"] {{ 
    border-radius: 8px; border: 1px solid var(--border); background: var(--bg-card); 
    color: var(--text-dark); font-weight: 700; transition: all 0.2s ease; 
    padding-top: 0.5rem; padding-bottom: 0.5rem; box-shadow: var(--shadow-sm);
}}
div[data-testid="stButton"] > button[kind="secondary"]:hover {{ 
    border-color: var(--f1-red); color: var(--f1-red); background: #FFF5F5; transform: translateY(-1px);
}}
div[data-testid="stButton"] > button[kind="primary"] {{ 
    border-radius: 8px; border: none; background: var(--f1-red); color: white; 
    font-weight: 700; box-shadow: 0 4px 12px rgba(225,6,0,0.3); transition: all 0.2s ease;
}}
div[data-testid="stButton"] > button[kind="primary"]:hover {{ 
    background: #C40500; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(225,6,0,0.4);
}}

/* Clean up selectbox */
div[data-baseweb="select"] > div {{ border-radius: 8px; border-color: var(--border); }}

/* Headers & Text */
h1, h2, h3, h4, h5, h6 {{ color: var(--f1-dark) !important; font-weight: 800; letter-spacing: -0.5px; }}
.small-muted {{ color: var(--text-muted) !important; font-size: 0.8rem; font-weight: 500; }}

/* Top Pills & Banners */
.header-center {{ display:flex; flex-direction:column; align-items:center; justify-content:center; gap:8px; width: 100%; }}
.logo-img {{ width:140px; height:140px; border-radius:16px; object-fit:cover; border:1px solid var(--border); box-shadow: var(--shadow-md); }}
.title-pill {{ background: var(--bg-card); color: var(--f1-dark); padding:10px 24px; border-radius:12px; font-weight:900; font-size:22px; box-shadow: var(--shadow-sm); border: 1px solid var(--border); text-align:center; text-transform: uppercase; letter-spacing: 0.5px; }}

/* Stat Summaries */
.stat-container {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(140px, 1fr)); gap: 12px; margin-top: 10px; margin-bottom: 24px; }}
.stat-card {{ background: var(--bg-card); padding: 16px 20px; border-radius: 12px; width: 100%; border: 1px solid var(--border); border-top: 4px solid var(--f1-red); box-shadow: var(--shadow-sm); transition: all 0.2s ease; }}
.stat-card:hover {{ box-shadow: var(--shadow-md); transform: translateY(-2px); }}
.stat-title {{ font-size: 0.75rem; color: var(--text-muted); margin-bottom: 6px; text-transform:uppercase; font-weight:700; letter-spacing: 0.5px;}}
.stat-value {{ font-size: 1.4rem; font-weight: 900; color: var(--f1-dark); margin-bottom: 2px; letter-spacing: -0.5px; }}

/* Dashboard Home Mini List */
.mini-list-card {{ background: var(--bg-card); padding: 20px; border-radius: 16px; border: 1px solid var(--border); box-shadow: var(--shadow-sm); transition: box-shadow 0.2s; }}
.mini-list-card:hover {{ box-shadow: var(--shadow-md); }}
.row-card {{ background: var(--bg-app); border-radius: 10px; padding: 12px 16px; margin-bottom: 8px; display: flex; justify-content: space-between; align-items: center; border: 1px solid transparent; transition: all 0.2s; }}
.row-card:hover {{ background: var(--bg-card); border-color: var(--border); transform: translateX(2px); box-shadow: var(--shadow-sm); }}
.rank-badge {{ width: 36px; height: 36px; flex-shrink: 0; border-radius: 10px; display:flex; align-items:center; justify-content:center; font-weight:900; font-size:14px; background: #F1F5F9; color: var(--text-muted); }}
.rank-badge.top1 {{ background: #FEF08A; color: #854D0E; box-shadow: inset 0 0 0 1px #FDE047; }}
.rank-badge.top2 {{ background: #E2E8F0; color: #475569; box-shadow: inset 0 0 0 1px #CBD5E1; }}
.rank-badge.top3 {{ background: #FED7AA; color: #9A3412; box-shadow: inset 0 0 0 1px #FDBA74; }}

/* Detail Grid */
.detail-grid {{ display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 16px; margin-top: 16px; }}
.detail-card {{ padding: 18px; border-radius: 12px; background: var(--bg-card); border: 1px solid var(--border); border-left: 4px solid var(--f1-dark); box-shadow: var(--shadow-sm); display: flex; flex-direction: column; transition: all 0.2s ease; }}
.detail-card:hover {{ transform: translateY(-3px); box-shadow: var(--shadow-hover); border-color: #CBD5E1; }}
.detail-title {{ font-size: 0.75rem; color: var(--text-muted); font-weight: 700; text-transform:uppercase; margin-bottom: 8px; display: flex; align-items: center; gap: 8px; letter-spacing: 0.5px;}}
.detail-value {{ font-size: 1.35rem; font-weight: 900; color: var(--text-dark); line-height: 1.1; margin-top: 2px; letter-spacing:-0.5px; word-wrap: break-word; }}

/* Banner Profil */
.emp-banner {{ background: var(--bg-card); padding: 24px; border-radius: 16px; border: 1px solid var(--border); border-top: 6px solid var(--f1-red); margin-bottom: 24px; display: flex; align-items: center; gap: 24px; box-shadow: var(--shadow-md); }}
.emp-avatar {{ width: 76px; height: 76px; flex-shrink: 0; border-radius: 50%; background: var(--f1-dark); display: flex; align-items: center; justify-content: center; font-size: 32px; font-weight: 900; color: white; border: 4px solid #F8FAFC; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }}
.emp-info-title {{ font-size: 1.6rem; font-weight: 900; text-transform:uppercase; margin:0; line-height:1.1; color: var(--f1-red); letter-spacing: -0.5px; }}

/* =========================================================
   TABEL LEADERBOARD RESPONSIVE (MOBILE & DESKTOP FIX)
   ========================================================= */
.table-header {{ display:flex; align-items:flex-end; padding: 4px 12px; margin-top:8px; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 8px; flex-direction: row; }}
.table-row {{ border-radius:8px; padding: 12px; display:flex; align-items:center; border:1px solid #E2E8F0; box-shadow:0 2px 4px rgba(0,0,0,0.05); gap: 8px; flex-direction: row; }}

.col-rank {{ width: 5%; font-weight:900; }}
.col-chg {{ width: 7%; }}
.col-id {{ width: 10%; font-size:0.85rem; font-weight:600; opacity:0.9; }}
.col-name {{ width: 18%; font-weight:800; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }}
.col-pos {{ width: 15%; font-size:0.85rem; font-weight:600; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }}
.col-area {{ width: 15%; font-size:0.8rem; font-weight:700; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; opacity:0.9; }}
.col-score {{ width: 15%; font-weight:900; font-size:1.05rem; }}
.col-growth {{ width: 15%; }}

@media (max-width: 768px) {{
    .logo-img {{ width: 100px; height: 100px; }}
    .title-pill {{ font-size: 18px; padding: 8px 16px; }}
    .emp-banner {{ flex-direction: column; text-align: center; gap: 16px; padding: 20px 16px; }}
    .row-card {{ flex-wrap: wrap; flex-direction: column; align-items: flex-start; gap: 12px; }}
    
    .table-header {{ display: none !important; }} 
    
    .table-row {{ 
        flex-direction: column; align-items: flex-start; 
        padding: 16px !important; position: relative; gap: 6px !important; 
    }}
    .table-row > div {{ 
        width: 100% !important; white-space: normal !important; 
        overflow: visible !important; text-align: left;
    }}
    
    .col-rank {{ position: absolute; top: 16px; right: 16px; font-size: 1.2rem; text-align: right !important; width: auto !important; }}
    .col-chg {{ position: absolute; top: 16px; right: 65px; width: auto !important; }}
    
    .col-id {{ margin-top: 4px; font-size: 0.9rem !important; }}
    .col-name {{ font-size: 1.15rem !important; border-bottom: 1px dashed rgba(0,0,0,0.2); padding-bottom: 8px; margin-bottom: 4px; }}
    .col-score {{ font-size: 1.2rem !important; margin-top: 4px; }}
}}
</style>
"""
init_db()

# ---------------------------
# 5. SESSION STATE
# ---------------------------
if "view" not in st.session_state: st.session_state.view = "home"
if "kode" not in st.session_state: st.session_state.kode = None
if "detail_nip" not in st.session_state: st.session_state.detail_nip = None
if "page_num" not in st.session_state: st.session_state.page_num = 1
if "is_admin" not in st.session_state: st.session_state.is_admin = False
if "show_update_panel" not in st.session_state: st.session_state.show_update_panel = False
if "kategori" not in st.session_state: st.session_state.kategori = "HOME"
if "logged_in" not in st.session_state: st.session_state.logged_in = False
if "current_user_nip" not in st.session_state: st.session_state.current_user_nip = None
if "current_user_nama" not in st.session_state: st.session_state.current_user_nama = None
if "visit_count" not in st.session_state: st.session_state.visit_count = 1
if "last_visit" not in st.session_state: st.session_state.last_visit = "Ini kunjungan pertama Anda"

st.markdown(ENHANCED_CSS, unsafe_allow_html=True)

# ---------------------------
# 6. ROUTING URL PARAMETER
# ---------------------------
params = st.query_params
if "kode" in params:
    st.session_state.view = "pegawai"
    st.session_state.kode = str(params.get("kode")).strip()
    st.query_params.clear()
if "view" in params:
    st.session_state.view = str(params.get("view"))
    st.query_params.clear()

def log_visitor(nip, nama):
    try:
        headers = st.context.headers
        ip_address = headers.get("X-Forwarded-For", headers.get("Host", "Unknown_IP"))
    except: ip_address = "Unknown_IP"
        
    waktu_sekarang = datetime.now(ZoneInfo("Asia/Makassar")).strftime("%Y-%m-%d %H:%M:%S")

    try:
        conn = sqlite3.connect(DB_PATH, timeout=15.0)
        conn.execute("INSERT INTO access_log (waktu, nip, nama, ip_address) VALUES (?, ?, ?, ?)", (waktu_sekarang, nip, nama, ip_address))
        conn.commit()
    except Exception: pass
    finally: conn.close()

def get_visit_stats(n):
    conn = sqlite3.connect(DB_PATH)
    stats = conn.execute("SELECT COUNT(*), MAX(waktu) FROM access_log WHERE nip = ?", (n,)).fetchone()
    conn.close()
    return (stats[0] if stats[0] else 0) + 1, stats[1] if stats[1] else "Ini kunjungan pertama Anda"

# --- GLOBAL F1 HELPER UNTUK SEMUA VIEW ---
def get_f1_style_global(area_code):
    if pd.isna(area_code) or str(area_code).strip() == "":
        return "#FFFFFF", "black"
        
    area = str(area_code).strip().upper()
    
    # Denpasar → Ferrari
    if area == "145":
        return "#E10600", "white"
    
    # Kuta → McLaren
    elif area == "175":
        return "#FF8700", "black"
    
    # Mataram → Aston Martin
    elif area == "161":
        return "#00D2BE", "white"
    
    # Kupang → Williams
    elif area == "181":
        return "#0600EF", "white"
    
    # Default
    else:
        return "#FFFFFF", "black"             # Haas

def get_area_name_global(area_code):
    area = str(area_code).strip().upper()
    mapping = {"145": "DENPASAR", "161": "MATARAM", "175": "KUTA", "181": "KUPANG", "R11": "INTERNAL REGION"}
    return mapping.get(area, area)

# Helper Badge Naik/Turun Khusus untuk Tabel Berwarna
def get_table_rank_change_html(change_val, base_val):
    if base_val == 0:
        return "<span style='background:#FEF08A; color:#854D0E; padding:2px 6px; border-radius:4px; font-weight:900; font-size:0.75rem; border:1px solid #FDE047;'>NEW</span>"
    if change_val > 0:
        return f"<span style='background:#ECFDF5; color:#10B981; padding:2px 6px; border-radius:4px; font-weight:900; font-size:0.8rem; border:1px solid #A7F3D0; box-shadow:0 1px 2px rgba(0,0,0,0.1);'>▲ {int(change_val)}</span>"
    elif change_val < 0:
        return f"<span style='background:#FFF5F5; color:#E10600; padding:2px 6px; border-radius:4px; font-weight:900; font-size:0.8rem; border:1px solid #FECACA; box-shadow:0 1px 2px rgba(0,0,0,0.1);'>▼ {abs(int(change_val))}</span>"
    else:
        return "<span style='background:#F1F5F9; color:#64748B; padding:2px 6px; border-radius:4px; font-weight:900; font-size:0.8rem; border:1px solid #E2E8F0;'>➖</span>"
# ---------------------------
# 7. TAMPILAN LOGIN
# ---------------------------
if not st.session_state.logged_in:
    st.markdown(f"<div class='header-center'><img src='{LOGO_PATH}' class='logo-img'/></div>", unsafe_allow_html=True)
    st.markdown("<h2 style='text-align:center; margin-top:24px; color:var(--f1-dark); letter-spacing:-0.5px;'>Paddock Access</h2>", unsafe_allow_html=True)
    st.markdown("<p style='text-align:center; color:var(--text-muted);'>Masukkan NIP Anda untuk mengakses GMM Raceboard</p>", unsafe_allow_html=True)
    
    _, col_login, _ = st.columns([1, 2, 1])
    with col_login:
        with st.form("login_form"):
            nip_input = st.text_input("NIP Pegawai")
            if st.form_submit_button("Masuk 🚀", use_container_width=True):
                nip_clean = nip_input.strip()
                conn = sqlite3.connect(DB_PATH)
                user_data = conn.execute("SELECT nama FROM pegawai WHERE nip = ?", (nip_clean,)).fetchone()
                conn.close()
                
                is_super_admin = ("admin_nip" in st.secrets and nip_clean == st.secrets["admin_nip"]) or ("admin_pass" in st.secrets and nip_clean.lower() == st.secrets["admin_pass"])
                
                if is_super_admin or user_data:
                    nama_user = "Administrator" if is_super_admin and not user_data else user_data[0]
                    v_count, l_visit = get_visit_stats(nip_clean)
                    
                    st.session_state.logged_in = True
                    st.session_state.current_user_nip = nip_clean
                    st.session_state.current_user_nama = nama_user
                    st.session_state.is_admin = is_super_admin
                    st.session_state.visit_count = v_count
                    st.session_state.last_visit = l_visit
                    log_visitor(nip_clean, nama_user)
                    st.rerun()
                else:
                    st.error("❌ NIP tidak ditemukan!")
    st.stop()

# ---------------------------
# 8. HEADER & MAIN NAVIGASI
# ---------------------------
st.markdown(f"""
<div style='text-align:right; margin-bottom:16px; color:var(--text-muted);'>
    <div style='font-size:0.85rem;'>Halo, <b style='color:var(--text-dark);'>{st.session_state.current_user_nama}</b> ({st.session_state.current_user_nip})</div>
    <div style='font-size:0.75rem; opacity:0.8; margin-top:2px;'>Kunjungan ke-{st.session_state.visit_count} | Terakhir: {st.session_state.last_visit}</div>
</div>
""", unsafe_allow_html=True)

st.markdown(f"<div class='header-center'><img src='{LOGO_PATH}' class='logo-img'/></div>", unsafe_allow_html=True)
st.markdown("""
<div class='header-center' style='margin-top: 16px; margin-bottom: 24px;'>
  <div class='title-pill'>GMM RACEBOARD FASE 3</div>
  <div style='color: var(--text-muted); font-weight:500; font-size:0.9rem; margin-top: 4px;'>25 April 2026</div>
</div>
""", unsafe_allow_html=True)

# --- BARIS 1: MENU UTAMA (VIEW) ---
menu_buttons = ["DASHBOARD", "LEADERBOARD CABANG", "LEADERBOARD PEGAWAI", "PENCARIAN"]
if st.session_state.get("is_admin", False): menu_buttons.append("ADMIN PANEL")
menu_buttons.append("LOGOUT")

cols = st.columns(len(menu_buttons))
for i, btn in enumerate(menu_buttons):
    is_active = False
    if btn == "DASHBOARD" and st.session_state.view == "home" and not st.session_state.show_update_panel: is_active = True
    elif btn == "LEADERBOARD CABANG" and st.session_state.view == "cabang" and not st.session_state.show_update_panel: is_active = True
    elif btn == "LEADERBOARD PEGAWAI" and st.session_state.view == "pegawai" and not st.session_state.show_update_panel: is_active = True
    elif btn == "PENCARIAN" and st.session_state.view == "cari" and not st.session_state.show_update_panel: is_active = True
    elif btn == "ADMIN PANEL" and st.session_state.show_update_panel: is_active = True

    if cols[i].button(btn, use_container_width=True, type="primary" if is_active else "secondary"):
        if btn == "DASHBOARD":
            st.session_state.view = "home"; st.session_state.kategori = "HOME"; st.session_state.show_update_panel = False; st.rerun()
        elif btn == "LEADERBOARD CABANG":
            st.session_state.view = "cabang"
            if st.session_state.kategori == "HOME": st.session_state.kategori = "LIVIN"
            st.session_state.show_update_panel = False; st.session_state.kode = None; st.session_state.page_num = 1; st.rerun()
        elif btn == "LEADERBOARD PEGAWAI":
            st.session_state.view = "pegawai"
            if st.session_state.kategori == "HOME": st.session_state.kategori = "LIVIN"
            st.session_state.show_update_panel = False; st.session_state.kode = "ALL"; st.session_state.page_num = 1; st.rerun()
        elif btn == "PENCARIAN":
            st.session_state.view = "cari"; st.session_state.show_update_panel = False; st.rerun()
        elif btn == "ADMIN PANEL":
            st.session_state.show_update_panel = not st.session_state.show_update_panel; st.rerun()
        elif btn == "LOGOUT":
            st.session_state.clear(); st.rerun()

# --- BARIS 2: TAB KATEGORI ---
if not st.session_state.show_update_panel and st.session_state.view in ["cabang", "pegawai"]:
    st.markdown("<div style='margin-top: 16px;'></div>", unsafe_allow_html=True)
    c1, c2, c3 = st.columns(3)
    if c1.button("📱 LIVIN", use_container_width=True, type="primary" if st.session_state.kategori == "LIVIN" else "secondary"): 
        st.session_state.kategori = "LIVIN"; st.rerun()
    if c2.button("🏪 MERCHANT", use_container_width=True, type="primary" if st.session_state.kategori == "MERCHANT" else "secondary"): 
        st.session_state.kategori = "MERCHANT"; st.rerun()
    if c3.button("💳 TRANSAKSI", use_container_width=True, type="primary" if st.session_state.kategori == "TRANSAKSI" else "secondary"): 
        st.session_state.kategori = "TRANSAKSI"; st.rerun()

st.markdown("<hr style='border-color: var(--border); margin: 24px 0px;'>", unsafe_allow_html=True)

# ---------------------------
# 9. HTML BUILDER & FORMATTER
# ---------------------------
def build_card_html(cards_tuple_list):
    html = "<div class='detail-grid'>"
    for icon, title, val_raw, base_raw, formatter in cards_tuple_list:
        val_str = formatter(val_raw)
        if title == "Kebutuhan": 
            html += f"<div class='detail-card highlight-card' style='grid-column:'><div class='detail-title'>{icon} {title}</div><div class='detail-value'>{val_raw}</div></div>"
            continue    
        is_penalti = title in ["Poin Off Us", "Trx Off Us"]
        growth_html = fmt_growth(val_raw, base_raw, formatter, is_penalty=is_penalti)
        bg_style = "background: #FFF5F5; border-left-color: var(--f1-red);" if is_penalti else ""
        html += f"<div class='detail-card' style='{bg_style}'><div class='detail-title'><span>{icon}</span> {title}</div><div class='detail-value'>{val_str}</div><div style='margin-top:6px;'>{growth_html}</div></div>"
    html += "</div>"
    return html

kategori_aktif = st.session_state.kategori
if kategori_aktif != "HOME" and st.session_state.view in ["cabang", "pegawai"]:
    fmt_fungsi = KAT_CONFIG[kategori_aktif]["fmt"]
    label_utama = KAT_CONFIG[kategori_aktif]["score_label"]
    label_kedua = KAT_CONFIG[kategori_aktif]["sec_label"]
    if st.session_state.view == "cabang" and kategori_aktif == "TRANSAKSI":
        fmt_fungsi, label_utama, label_kedua = fmt_num, "Total Poin", "Poin On Us"

# ---------------------------
# 10. RENDERER PROFIL (DETAIL)
# ---------------------------

def render_profil_cabang(kode_cabang):
    conn = sqlite3.connect(DB_PATH)
    
    # Query Agregasi: Menggabungkan data cabang dan menjumlahkan seluruh performa pegawainya
    query_cabang = """
        SELECT 
            c.kode_cabang, c.unit, c.area, c.kelas_cabang,
            COUNT(p.nip) as jml_pegawai,
            SUM(p.end_balance) as end_balance, SUM(p.end_balance_base) as end_balance_base,
            SUM(p.cif_akuisisi) as cif_akuisisi, SUM(p.cif_akuisisi_base) as cif_akuisisi_base,
            SUM(p.cif_setor) as cif_setor, SUM(p.cif_setor_base) as cif_setor_base,
            SUM(p.cif_sudah_transaksi) as cif_sudah_transaksi, SUM(p.cif_sudah_transaksi_base) as cif_sudah_transaksi_base,
            SUM(p.frek_dari_cif_akuisisi) as frek_dari_cif_akuisisi, SUM(p.frek_dari_cif_akuisisi_base) as frek_dari_cif_akuisisi_base,
            SUM(p.rata_rata) as rata_rata, SUM(p.rata_rata_base) as rata_rata_base,
            SUM(p.total_referral_livin) as total_referral_livin, SUM(p.total_referral_livin_base) as total_referral_livin_base,
            SUM(p.total_referral_edc) as total_referral_edc, SUM(p.total_referral_edc_base) as total_referral_edc_base,
            SUM(p.total_poin_transaksi) as total_poin_transaksi, SUM(p.total_poin_transaksi_base) as total_poin_transaksi_base,
            SUM(p.poin_on_us) as poin_on_us, SUM(p.poin_on_us_base) as poin_on_us_base,
            SUM(p.poin_off_us) as poin_off_us, SUM(p.poin_off_us_base) as poin_off_us_base,
            SUM(p.frek_on_us) as frek_on_us, SUM(p.frek_on_us_base) as frek_on_us_base,
            SUM(p.frek_off_us) as frek_off_us, SUM(p.frek_off_us_base) as frek_off_us_base
        FROM cabang c
        LEFT JOIN pegawai p ON c.kode_cabang = p.kode_cabang AND p.is_active = 1
        WHERE c.kode_cabang = ?
        GROUP BY c.kode_cabang
    """
    df_detail = pd.read_sql_query(query_cabang, conn, params=(kode_cabang,))
    
    if df_detail.empty: 
        st.error("Data cabang tidak ditemukan.")
        conn.close()
        return False

    r = df_detail.iloc[0]
    
    # Ambil Ranking Cabang secara Live dari fungsi leaderboard
    df_l = get_cabang_leaderboard("LIVIN")
    df_m = get_cabang_leaderboard("MERCHANT")
    df_t = get_cabang_leaderboard("TRANSAKSI")
    
    rank_livin = df_l[df_l['kode_cabang'] == kode_cabang]['rank_default'].values[0] if not df_l[df_l['kode_cabang'] == kode_cabang].empty else "-"
    rank_merchant = df_m[df_m['kode_cabang'] == kode_cabang]['rank_default'].values[0] if not df_m[df_m['kode_cabang'] == kode_cabang].empty else "-"
    rank_trx = df_t[df_t['kode_cabang'] == kode_cabang]['rank_default'].values[0] if not df_t[df_t['kode_cabang'] == kode_cabang].empty else "-"
    
    conn.close()

    # Styling Banner berdasarkan Area Tim F1
    bg_col, txt_col = get_f1_style_global(r.get('area', ''))
    area_str = get_area_name_global(r.get('area', ''))

    st.markdown(f"""
    <div class="emp-banner" style="border-top: 6px solid {bg_col if bg_col != '#FFFFFF' else 'var(--f1-dark)'};">
        <div class="emp-avatar" style="background-color:{bg_col}; color:{txt_col}; border: 4px solid #F8FAFC;">🏢</div>
        <div>
            <div class="emp-info-title" style="color: {bg_col if bg_col != '#FFFFFF' else 'var(--f1-dark)'};">{r.get('unit','-')}</div>
            <div style="color: var(--text-muted); font-size: 0.95rem; font-weight:600; margin-top: 6px;">KODE CABANG: <span style="color:var(--text-dark);">{r['kode_cabang']}</span> &nbsp;•&nbsp; KELAS: <span style="color:var(--text-dark);">{r.get('kelas_cabang','-')}</span></div>
            <div style="color: var(--text-muted); font-size: 0.95rem; font-weight:600; margin-top: 2px;">TOTAL PEGAWAI: <span style="color:var(--text-dark);">{r.get('jml_pegawai', 0)} Orang</span></div>
            <div style="background-color:{bg_col}; color:{txt_col}; padding:4px 10px; border-radius:6px; font-size:0.75rem; font-weight:800; display:inline-block; margin-top:10px; border:1px solid #E2E8F0; letter-spacing:0.5px;">AREA {area_str.upper()}</div>
        </div>
    </div>
    """, unsafe_allow_html=True)

    # -- LIVIN SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:24px;'>📱 LIVIN (Akumulasi Cabang) <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_livin}</span></h4>", unsafe_allow_html=True)
    cards_livin = [
        ("🏦", "End Balance", r.get("end_balance",0), r.get("end_balance_base",0), fmt_rp),
        ("📌", "CIF Akuisisi", r.get("cif_akuisisi",0), r.get("cif_akuisisi_base",0), fmt_num),
        ("💰", "CIF Setor", r.get("cif_setor",0), r.get("cif_setor_base",0), fmt_num),
        ("🔄", "CIF Transaksi", r.get("cif_sudah_transaksi",0), r.get("cif_sudah_transaksi_base",0), fmt_num),
        ("⏱️", "Frek Dari CIF", r.get("frek_dari_cif_akuisisi",0), r.get("frek_dari_cif_akuisisi_base",0), fmt_num),
        ("📊", "Total Rata-rata", r.get("rata_rata",0), r.get("rata_rata_base",0), fmt_rp)
    ]
    st.markdown(build_card_html(cards_livin), unsafe_allow_html=True)

    # -- MERCHANT SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:32px;'>🏪 MERCHANT (Akumulasi Cabang) <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_merchant}</span></h4>", unsafe_allow_html=True)
    tot_ref = int(r.get("total_referral_edc",0) + r.get("total_referral_livin",0))
    tot_ref_base = int(r.get("total_referral_edc_base",0) + r.get("total_referral_livin_base",0))
    cards_merchant = [
        ("🏪", "Total Refferal", tot_ref, tot_ref_base, fmt_num),
        ("🖥️", "Referral EDC", r.get("total_referral_edc",0), r.get("total_referral_edc_base",0), fmt_num),
        ("💳", "Referral LVM", r.get("total_referral_livin",0), r.get("total_referral_livin_base",0), fmt_num)
    ]
    st.markdown(build_card_html(cards_merchant), unsafe_allow_html=True)
    
    # -- TRANSAKSI SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:32px;'>💳 TRANSAKSI (Akumulasi Cabang) <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_trx}</span></h4>", unsafe_allow_html=True)

    poin_on_us, poin_off_us = r.get("poin_on_us", 0), r.get("poin_off_us", 0)
    trx_on_us, trx_off_us = r.get("frek_on_us", 0), r.get("frek_off_us", 0)
    total_trx = trx_on_us + trx_off_us
    
    # Kalkulasi Persentase Agregat Cabang
    pct_on_us = (trx_on_us / total_trx) if total_trx > 0 else 0
    
    # Kalkulasi Base Persentase Agregat Cabang
    trx_on_us_base = r.get("frek_on_us_base", 0)
    total_trx_base = trx_on_us_base + r.get("frek_off_us_base", 0)
    pct_on_us_base = (trx_on_us_base / total_trx_base) if total_trx_base > 0 else 0

    if pct_on_us < 0.80:
        kebutuhan_val = int(math.ceil((0.8 * total_trx - trx_on_us) / 0.2)) if total_trx > 0 else 0
        kebutuhan_display = f"{kebutuhan_val} Kali <span>Unit ini perlu <b>{kebutuhan_val} kali</b> transaksi On Us lagi agar mencapai 80%</span>"
    else:
        kebutuhan_display = "Tercapai 🎉<span>Performa Unit sangat baik! Pertahankan On Us Rate.</span>"

    cards_transaksi = [
        ("📈", "Total Poin", r.get("total_poin_transaksi",0), r.get("total_poin_transaksi_base",0), fmt_num),
        ("🏦", "Poin On Us", poin_on_us, r.get("poin_on_us_base",0), fmt_num),
        ("🌍", "Poin Off Us", poin_off_us, r.get("poin_off_us_base",0), fmt_num),
        ("📦", "Total Trx", total_trx, total_trx_base, fmt_num), 
        ("🔄", "Trx On Us", trx_on_us, trx_on_us_base, fmt_num),
        ("🌐", "Trx Off Us", trx_off_us, r.get("frek_off_us_base",0), fmt_num),
        ("📊", "% On Us Cabang", pct_on_us, pct_on_us_base, fmt_pct),
        ("🎯", "% Target", "80.0%", None, str),
        ("💡", "Kebutuhan", kebutuhan_display, None, str)  
    ]
    st.markdown(build_card_html(cards_transaksi), unsafe_allow_html=True)
    return True
def render_profil_pegawai(nip):
    conn = sqlite3.connect(DB_PATH)
    df_detail = pd.read_sql_query("SELECT * FROM pegawai WHERE nip = ?", conn, params=(nip,))
    if df_detail.empty: st.error("Data pegawai tidak ditemukan."); conn.close(); return False

    r = df_detail.iloc[0]
    
    def get_global_rank(col_name, score, tie_col=None, tie_score=None):
        cur = conn.cursor()
        if tie_col and tie_score: cur.execute(f"SELECT COUNT(*) + 1 FROM pegawai WHERE {col_name} > ? OR ({col_name} = ? AND {tie_col} > ?)", (score, score, tie_score))
        else: cur.execute(f"SELECT COUNT(*) + 1 FROM pegawai WHERE {col_name} > ?", (score,))
        return cur.fetchone()[0]

    rank_livin = get_global_rank("end_balance", r["end_balance"])
    rank_merchant = get_global_rank("total_referral_edc", r["total_referral_edc"])
    rank_pct_on_us = get_global_rank("pct_on_us", r.get("pct_on_us",0), "total_poin_transaksi", r.get("total_poin_transaksi",0))
    conn.close()

    st.markdown(f"""
    <div class="emp-banner">
        <div class="emp-avatar">{r['nama'][0].upper() if r['nama'] else "?"}</div>
        <div>
            <div class="emp-info-title">{r['nama']}</div>
            <div style="color: var(--text-muted); font-size: 0.95rem; font-weight:600; margin-top: 6px;">NIP: <span style="color:var(--text-dark);">{r['nip']}</span> &nbsp;•&nbsp; POSISI: <span style="color:var(--text-dark);">{r.get('posisi','')}</span></div>
            <div style="color: var(--text-muted); font-size: 0.95rem; font-weight:600; margin-top: 2px;">CABANG: <span style="color:var(--text-dark);">{r.get('unit','')}</span></div>
        </div>
    </div>
    """, unsafe_allow_html=True)

    # -- LIVIN SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:24px;'>📱 LIVIN <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_livin}</span></h4>", unsafe_allow_html=True)
    cards_livin = [
        ("🏦", "End Balance", r.get("end_balance",0), r.get("end_balance_base",0), fmt_rp),
        ("📌", "CIF Akuisisi", r.get("cif_akuisisi",0), r.get("cif_akuisisi_base",0), fmt_num),
        ("💰", "CIF Setor", r.get("cif_setor",0), r.get("cif_setor_base",0), fmt_num),
        ("🔄", "CIF Transaksi", r.get("cif_sudah_transaksi",0), r.get("cif_sudah_transaksi_base",0), fmt_num),
        ("⏱️", "Frek Dari CIF", r.get("frek_dari_cif_akuisisi",0), r.get("frek_dari_cif_akuisisi_base",0), fmt_num),
        ("📊", "Rata-rata", r.get("rata_rata",0), r.get("rata_rata_base",0), fmt_rp)
    ]
    st.markdown(build_card_html(cards_livin), unsafe_allow_html=True)

    # -- MERCHANT SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:32px;'>🏪 MERCHANT <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_merchant}</span></h4>", unsafe_allow_html=True)
    tot_ref = int(r.get("total_referral_edc",0) + r.get("total_referral_livin",0))
    tot_ref_base = int(r.get("total_referral_edc_base",0) + r.get("total_referral_livin_base",0))
    cards_merchant = [
        ("🏪", "Total Refferal", tot_ref, tot_ref_base, fmt_num),
        ("🖥️", "Referral EDC", r.get("total_referral_edc",0), r.get("total_referral_edc_base",0), fmt_num),
        ("💳", "Referral LVM", r.get("total_referral_livin",0), r.get("total_referral_livin_base",0), fmt_num)
    ]
    st.markdown(build_card_html(cards_merchant), unsafe_allow_html=True)
    
    # -- TRANSAKSI SECTION --
    st.markdown(f"<h4 style='color:var(--f1-dark); margin-top:32px;'>💳 TRANSAKSI <span style='color:var(--f1-red); font-size:0.85rem; background:#FFF5F5; padding:4px 12px; border-radius:12px; margin-left:8px; border: 1px solid #FECACA; vertical-align:middle;'>🏆 Rank #{rank_pct_on_us}</span></h4>", unsafe_allow_html=True)

    poin_on_us, poin_off_us = r.get("poin_on_us", 0), r.get("poin_off_us", 0)
    trx_on_us, trx_off_us = r.get("frek_on_us", 0), r.get("frek_off_us", 0)
    total_trx = trx_on_us + trx_off_us
    pct_on_us = r.get("pct_on_us", 0)

    if pct_on_us < 0.80:
        kebutuhan_val = int(math.ceil((0.8 * total_trx - trx_on_us) / 0.2)) if total_trx > 0 else 0
        kebutuhan_display = f"{kebutuhan_val} Kali <span>Kamu perlu <b>{kebutuhan_val} kali</b> transaksi On Us agar mencapai 80%<br>*(Asumsi tanpa menambah Trx Off Us)*</span>"
    else:
        kebutuhan_display = "Tercapai 🎉<span>Luar biasa! Jaga agar selalu bertransaksi On Us</span>"

    cards_transaksi = [
        ("📈", "Total Poin", r.get("total_poin_transaksi",0), r.get("total_poin_transaksi_base",0), fmt_num),
        ("🏦", "Poin On Us", poin_on_us, r.get("poin_on_us_base",0), fmt_num),
        ("🌍", "Poin Off Us", poin_off_us, r.get("poin_off_us_base",0), fmt_num),
        ("📦", "Total Trx", total_trx, (r.get("frek_on_us_base",0)+r.get("frek_off_us_base",0)), fmt_num), 
        ("🔄", "Trx On Us", trx_on_us, r.get("frek_on_us_base",0), fmt_num),
        ("🌐", "Trx Off Us", trx_off_us, r.get("frek_off_us_base",0), fmt_num),
        ("📊", "% On Us", pct_on_us, r.get("pct_on_us_base",0), fmt_pct),
        ("🎯", "% Target", "80.0%", None, str),
        ("💡", "Kebutuhan", kebutuhan_display, None, str)  
    ]
    st.markdown(build_card_html(cards_transaksi), unsafe_allow_html=True)
    return True

# ---------------------------
# 12. ROUTING LOGIC UTAMA
# ---------------------------
if st.session_state.view == "detail_pegawai":
    nip = st.session_state.get("detail_nip")
    if not nip: st.error("NIP tidak ditemukan."); st.session_state.view = "pegawai"; st.rerun()
    else:
        render_profil_pegawai(nip)
        st.markdown("<br><hr style='border-color:var(--border); margin-top:30px;'>", unsafe_allow_html=True)
        if st.button("⬅️ Kembali ke Daftar Pegawai", use_container_width=True): st.session_state.view = "pegawai"; st.rerun()

# --- View: PENCARIAN TERPADU ---
elif st.session_state.view == "cari" and not st.session_state.show_update_panel:
    st.markdown("<h2 style='margin-bottom:8px;'>🔍 Pencarian Profil Terpadu</h2>", unsafe_allow_html=True)
    st.markdown("<p class='small-muted' style='margin-bottom:24px;'>Cari profil spesifik berdasarkan Nama, NIP Pegawai, atau Nama Unit Cabang.</p>", unsafe_allow_html=True)

    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    
    # Ambil Daftar Pegawai
    cur.execute("SELECT nip, nama FROM pegawai ORDER BY nama ASC")
    peg_list = [f"👤 {row[0]} - {row[1]}" for row in cur.fetchall()]
    
    # Ambil Daftar Cabang
    cur.execute("SELECT kode_cabang, unit FROM cabang WHERE kode_cabang IS NOT NULL AND TRIM(kode_cabang) != '' AND LOWER(kode_cabang) NOT IN ('unknown', 'nan','aktif') ORDER BY unit ASC")
    cab_list = [f"🏢 {row[0]} - {row[1]}" for row in cur.fetchall()]
    conn.close()

    all_options = ["-- Ketik atau Pilih Disini --"] + cab_list + peg_list
    
    # Render Selectbox
    selected_profile = st.selectbox("Pilih Cabang / Pegawai (Ketik untuk mencari):", options=all_options)

    if selected_profile != "-- Ketik atau Pilih Disini --":
        st.markdown("<hr style='border-color:var(--border); margin-top:8px; margin-bottom:24px;'>", unsafe_allow_html=True)
        
        # Ekstrak Tipe dan ID
        is_pegawai = selected_profile.startswith("👤")
        raw_text = selected_profile[2:]
        entity_id = raw_text.split(" - ")[0].strip()

        # Render Profil
        if is_pegawai: 
            render_profil_pegawai(entity_id)
        else: 
            # Memanggil fungsi render cabang
            if "render_profil_cabang" in globals():
                render_profil_cabang(entity_id)
            else:
                st.warning(f"⚠️ Fungsi `render_profil_cabang` belum didefinisikan di dalam kode untuk merender detail cabang: {entity_id}")


# --- View: HOME DASHBOARD ---
elif st.session_state.view == "home" and not st.session_state.show_update_panel:
    st.markdown("<h2 style='margin-bottom: 4px;'>🏠 Dashboard Summary</h2>", unsafe_allow_html=True)
    st.markdown("<p class='small-muted' style='margin-bottom: 24px; font-size:1rem;'>Top 10 performa Cabang & Pegawai beserta perubahan posisi klasemen.</p>", unsafe_allow_html=True)
    
    kats = ["LIVIN", "MERCHANT", "TRANSAKSI"]
        
    def get_area_name(area_code):
        area = str(area_code).strip().upper()
        mapping = {
            "145": "AREA DENPASAR",
            "161": "AREA MATARAM",
            "175": "AREA KUTA",
            "181": "AREA KUPANG",
            "R11": "INTERNAL AREA/REGION"
        }
        return mapping.get(area, area) # Kembalikan kode asli jika tidak ada di mapping

    # --- LOGIKA INDIKATOR NAIK/TURUN POSISI ---
    def get_rank_change_html(change_val, base_val):
        # Jika nilai base 0 (belum ada data sblmnya), anggap pendatang baru
        if base_val == 0:
            return "<span style='color:#EAB308; font-weight:800; font-size:0.8rem; letter-spacing:0.5px;'>NEW</span>"
        if change_val > 0:
            return f"<span style='color:#10B981; font-weight:900; font-size:0.95rem;'>▲ {int(change_val)}</span>"
        elif change_val < 0:
            return f"<span style='color:#E10600; font-weight:900; font-size:0.95rem;'>▼ {abs(int(change_val))}</span>"
        else:
            return "<span style='color:#94A3B8; font-weight:900; font-size:1.1rem;'>➖</span>"

    def render_mini_list(title, df_list, name_col, score_col, base_col, fmt_fn, is_pegawai=False):
        html = f"<div class='mini-list-card'><h5 style='margin-bottom:16px; font-size: 1.1rem; color: var(--f1-dark); display:flex; align-items:center; gap:8px;'><span>🏆</span> {title}</h5>"
        if df_list.empty: return html + "<div class='small-muted'>Data belum tersedia.</div></div>"
            
        for idx, r in enumerate(df_list.to_dict('records')):
            name = r[name_col]
            val_html = f"<span style='color:var(--f1-dark); font-weight:900; font-size:1.05rem;'>{fmt_fn(r[score_col])}</span>"
            
            # Styling untuk Peringkat (1, 2, 3, dan seterusnya)
            rank_num = r.get('rank_current', idx + 1)
            if rank_num == 1: rank_cls, rank_style = "top1", ""
            elif rank_num == 2: rank_cls, rank_style = "top2", ""
            elif rank_num == 3: rank_cls, rank_style = "top3", ""
            else: rank_cls, rank_style = "", "background: #F8FAFC; color: #475569; border: 1px solid #CBD5E1;"
            
            # Area Logic & Penentuan Teks Badge
            area_code = str(r.get('area', '')).strip().upper()
            bg_col, txt_col = get_f1_style_global(area_code)
            area_name_str = get_area_name(area_code)
            
            if is_pegawai:
                unit_name = str(r.get('unit', '-')).strip()
                badge_text = f"{unit_name} — {area_name_str}"
            else:
                badge_text = f"{area_name_str}"
            
            # Hitung Status Perubahan
            change_html = get_rank_change_html(r.get('rank_change', 0), r.get(base_col, 0))
            
            html += f"<div class='row-card' style='padding: 8px 12px; margin-bottom: 6px;'>"
            
            # Kolom Kiri: Rank & Nama (Flexbox dengan batasan overflow agar teks panjang tidak merusak layout)
            html += f"<div style='display:flex; align-items:center; gap:12px; flex: 1; min-width: 0;'>"
            html += f"<div class='rank-badge {rank_cls}' style='margin:0; {rank_style}; flex-shrink: 0;'>{int(rank_num)}</div>"
            html += f"<div class='row-meta' style='min-width: 0; overflow: hidden;'>"
            html += f"<div class='unit' style='font-weight:700; color:var(--f1-dark); font-size:0.9rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;'>{name}</div>"
            html += f"<div style='background-color:{bg_col}; color:{txt_col}; padding:2px 6px; border-radius:4px; font-size:0.65rem; font-weight:800; display:inline-block; margin-top:2px; border:1px solid #E2E8F0; letter-spacing:0.5px; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; max-width:100%;'>{badge_text.upper()}</div>"
            html += f"</div></div>"
            
            # Kolom Kanan: Skor & Indikator Perubahan Posisi
            html += f"<div class='row-right' style='display:flex; align-items:center; gap:16px; justify-content:flex-end; flex-shrink: 0;'>"
            html += f"<div style='text-align:right;'>{val_html}</div>"
            html += f"<div style='width: 40px; text-align:center;'>{change_html}</div>"
            html += f"</div></div>"
        return html + "</div>"
    
    for kat in kats:
        st.markdown(f"<h3 style='color: var(--f1-red); margin-top: 40px; border-bottom: 2px solid var(--border); padding-bottom: 12px; margin-bottom: 20px;'>📊 KATEGORI {kat}</h3>", unsafe_allow_html=True)
        
        # Ambil seluruh data terlebih dahulu untuk kalkulasi rank global
        df_c = get_cabang_leaderboard(kat)
        df_p = get_pegawai("ALL", kat)
        
        fmt_fn_p = KAT_CONFIG[kat]["fmt"]
        fmt_fn_c = fmt_num if kat == "TRANSAKSI" else KAT_CONFIG[kat]["fmt"]
        
        # --- KALKULASI RANKING CABANG ---
        if not df_c.empty:
            df_c['rank_current'] = df_c['total_balance'].rank(method='min', ascending=False).astype(int)
            df_c['rank_base'] = df_c['total_balance_base'].rank(method='min', ascending=False).astype(int)
            df_c['rank_change'] = df_c['rank_base'] - df_c['rank_current']
            
        # --- KALKULASI RANKING PEGAWAI ---
        if not df_p.empty:
            df_p['rank_current'] = df_p['score_utama'].rank(method='min', ascending=False).astype(int)
            df_p['rank_base'] = df_p['score_utama_base'].rank(method='min', ascending=False).astype(int)
            df_p['rank_change'] = df_p['rank_base'] - df_p['rank_current']
        
        # Potong menjadi Top 10 setelah kalkulasi selesai
        top_c = df_c.head(10) if not df_c.empty else df_c
        top_p = df_p.head(10) if not df_p.empty else df_p
        
        c1, c2 = st.columns(2)
        # Perhatikan tambahan parameter is_pegawai=False/True di bawah ini
        with c1: st.markdown(render_mini_list(f"Top 10 Cabang", top_c, "unit", "total_balance", "total_balance_base", fmt_fn_c, is_pegawai=False), unsafe_allow_html=True)
        with c2: st.markdown(render_mini_list(f"Top 10 Pegawai", top_p, "nama", "score_utama", "score_utama_base", fmt_fn_p, is_pegawai=True), unsafe_allow_html=True)


# --- View: CABANG LEADERBOARD (TABEL) ---
elif st.session_state.view == "cabang" and not st.session_state.show_update_panel:
    st.markdown(f"<h2 style='margin-bottom:24px;'>🏢 Leaderboard Cabang <span style='color:var(--text-light); font-weight:400;'>/ {kategori_aktif}</span></h2>", unsafe_allow_html=True)
    df = get_cabang_leaderboard(kategori_aktif)
    
    # 1. Kalkulasi Rank Change Cabang
    if not df.empty:
        df['rank_current'] = df['total_balance'].rank(method='min', ascending=False).fillna(0).astype(int)
        df['rank_base'] = df['total_balance_base'].rank(method='min', ascending=False).fillna(0).astype(int)
        df['rank_change'] = df['rank_base'] - df['rank_current']
    
    area_options = ["All Area"] + sorted(df['area'].dropna().unique())
    kelas_options = ["All Kelas"] + sorted(df['kelas_cabang'].dropna().unique())

    # Form Filter & Sorting Cabang
    colA, colB, colC, colD = st.columns(4)
    with colA: area_filter = st.selectbox("Filter Area", options=area_options)
    with colB: kelas_filter = st.selectbox("Filter Kelas Cabang", options=kelas_options)
    with colC: 
        sort_options_c = {f"{label_utama}": "total_balance", f"Growth {label_utama}": "growth_score", f"{label_kedua}": "total_cif", f"Growth {label_kedua}": "growth_cif"}
        sort_by_c = st.selectbox("Sortir Berdasarkan", options=list(sort_options_c.keys()))
    with colD:
        sort_order_c = st.selectbox("Urutan", ["Tertinggi ➔ Terendah", "Terendah ➔ Tertinggi"])

    if area_filter != "All Area": df = df[df['area'] == area_filter]
    if kelas_filter != "All Kelas": df = df[df['kelas_cabang'] == kelas_filter]
    if not df.empty:
        is_ascending = (sort_order_c == "Terendah ➔ Tertinggi")
        df = df.sort_values(by=sort_options_c[sort_by_c], ascending=is_ascending)

# Render Header
    header_html = f"""
    <div class="table-header">
        <div class="col-rank" style="color:var(--text-light); text-transform:uppercase;">RANK</div>
        <div class="col-chg" style="color:var(--text-light); text-transform:uppercase;">CHG</div>
        <div class="col-id" style="color:var(--text-light); text-transform:uppercase;">KODE</div>
        <div class="col-name" style="color:var(--text-light); text-transform:uppercase;">NAMA CABANG</div>
        <div class="col-score" style="color:var(--text-light); text-transform:uppercase;">{label_utama}</div>
        <div class="col-growth" style="color:var(--text-light); text-transform:uppercase;">GROWTH</div>
        <div class="col-score" style="color:var(--text-light); text-transform:uppercase;">{label_kedua}</div>
        <div class="col-growth" style="color:var(--text-light); text-transform:uppercase;">GROWTH</div>
    </div>
    """
    c_head, c_btn_head = st.columns([8.5, 1.5])
    with c_head: st.markdown(header_html, unsafe_allow_html=True)
    with c_btn_head: st.markdown("<div style='font-size:0.75rem; font-weight:800; color:var(--text-light); text-transform:uppercase; text-align:center; margin-top:28px;'>AKSI</div>", unsafe_allow_html=True)

    if df.empty:
        st.info("Tidak ada data cabang sesuai filter.")
    else:
        for idx, (_, r) in enumerate(df.iterrows(), start=1):
            bg_col, txt_col = get_f1_style_global(r.get('area', ''))
            change_badge = get_table_rank_change_html(r.get('rank_change', 0), r.get('total_balance_base', 0))
            
            # Render Baris Data Cabang
            row_html = f"""
            <div class="table-row" style="background-color:{bg_col};">
                <div class="col-rank" style="color:{txt_col};">#{idx}</div>
                <div class="col-chg">{change_badge}</div>
                <div class="col-id" style="color:{txt_col};">{r['kode_cabang']}</div>
                <div class="col-name" style="color:{txt_col};">{r.get('unit','-')}</div>
                <div class="col-score" style="color:{txt_col};">{fmt_fungsi(r['total_balance'])}</div>
                <div class="col-growth">{fmt_growth(r['total_balance'], r['total_balance_base'], fmt_fungsi)}</div>
                <div class="col-score" style="color:{txt_col};">{fmt_num(r['total_cif'])}</div>
                <div class="col-growth">{fmt_growth(r['total_cif'], r['total_cif_base'], fmt_num)}</div>
            </div>
            """
            
            c_data, c_btn = st.columns([8.5, 1.5], vertical_alignment="center")
            with c_data: st.markdown(row_html, unsafe_allow_html=True)
            with c_btn:
                if st.button("Detail", key=f"btn_cb_{r['kode_cabang']}", use_container_width=True):
                    st.session_state.view = "pegawai"; st.session_state.kode = r['kode_cabang']; st.rerun()

# --- View: PEGAWAI LEADERBOARD (TABEL) ---
elif st.session_state.view == "pegawai" and not st.session_state.show_update_panel:
    st.markdown(f"<h2 style='margin-bottom:24px;'>👨‍💼 Leaderboard Pegawai <span style='color:var(--text-light); font-weight:400;'>/ {kategori_aktif}</span></h2>", unsafe_allow_html=True)
    
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

# ------------------ GANTI BAGIAN INI ------------------
    # Mengubah proporsi kolom dari 3 menjadi 4 untuk menyisipkan filter posisi
    colA, colPos, colB, colC = st.columns([1.5, 1.5, 1.5, 1.2])
    
    with colA: 
        chosen = st.selectbox("Pilih Area/Cabang:", options=options, index=default_index)
        
        # Eksekusi penentuan kode lebih awal agar bisa langsung tarik data dfp_all
        if chosen == "ALL": st.session_state.kode = "ALL"
        elif " — " in chosen: st.session_state.kode = chosen.split(" — ",1)[0].strip()
        else: st.session_state.kode = chosen

    # Tarik data sementara berdasarkan Cabang/Area untuk mengekstrak list Posisi
    dfp_all_temp = get_pegawai(st.session_state.kode, kategori_aktif)

    # Buat list dropdown posisi dinamis (hanya munculkan posisi yang ada di cabang/area terpilih)
    if not dfp_all_temp.empty and 'posisi' in dfp_all_temp.columns:
        posisi_unik = sorted([p for p in dfp_all_temp['posisi'].dropna().unique() if str(p).strip() != ""])
        list_posisi = ["Semua Posisi"] + posisi_unik
    else:
        list_posisi = ["Semua Posisi"]

    with colPos: 
        posisi_terpilih = st.selectbox("Filter Posisi:", options=list_posisi)

    with colB: 
        sort_options_p = {f"{label_utama}": "score_utama", f"Growth {label_utama}": "growth_score", f"{label_kedua}": "score_kedua", f"Growth {label_kedua}": "growth_kedua"}
        sort_by_p = st.selectbox("Sortir Berdasarkan", options=list(sort_options_p.keys()))
        
    with colC: 
        sort_order_p = st.selectbox("Urutan", ["Tertinggi ➔ Terendah", "Terendah ➔ Tertinggi"])

    # Terapkan filter posisi yang dipilih ke dataframe utama
    dfp_all = dfp_all_temp.copy()
    if posisi_terpilih != "Semua Posisi":
        dfp_all = dfp_all[dfp_all['posisi'] == posisi_terpilih]
    # ------------------------------------------------------

    dfp_all = get_pegawai(st.session_state.kode, kategori_aktif)

    # Kalkulasi Rank Change Pegawai
    if not dfp_all.empty:
        dfp_all['rank_current'] = dfp_all['score_utama'].rank(method='min', ascending=False).fillna(0).astype(int)
        dfp_all['rank_base'] = dfp_all['score_utama_base'].rank(method='min', ascending=False).fillna(0).astype(int)
        dfp_all['rank_change'] = dfp_all['rank_base'] - dfp_all['rank_current']

        total_pegawai = len(dfp_all)
        if kategori_aktif == "TRANSAKSI":
            val_akumulasi, label_akumulasi, fmt_akumulasi = dfp_all["total_poin_transaksi"].sum(), "Total Poin", fmt_num
            avg_balance = dfp_all["total_poin_transaksi"].mean()
        else:
            val_akumulasi, label_akumulasi, fmt_akumulasi = dfp_all["score_utama"].sum(), f"Akumulasi {label_utama}", fmt_fungsi
            avg_balance = dfp_all["score_utama"].mean()
            
        total_cif = dfp_all["score_kedua"].sum()
        
        st.markdown(f"""
        <div class="stat-container" style="margin-bottom:32px;">
            <div class="stat-card"><div class="stat-title">Total Pegawai</div><div class="stat-value">{total_pegawai}</div></div>
            <div class="stat-card"><div class="stat-title">{label_akumulasi}</div><div class="stat-value" style="color:var(--f1-red);">{fmt_akumulasi(val_akumulasi)}</div></div>
            <div class="stat-card"><div class="stat-title">Rata-rata {label_utama}</div><div class="stat-value">{fmt_fungsi(avg_balance)}</div></div>
            <div class="stat-card"><div class="stat-title">Total {label_kedua}</div><div class="stat-value">{fmt_num(total_cif)}</div></div>
        </div>
        """, unsafe_allow_html=True)

        is_ascending_p = (sort_order_p == "Terendah ➔ Tertinggi")
        dfp_all = dfp_all.sort_values(by=sort_options_p[sort_by_p], ascending=is_ascending_p)

        # Render Header Pegawai
        header_html = f"""
        <div class="table-header">
            <div class="col-rank" style="color:var(--text-light); text-transform:uppercase;">RANK</div>
            <div class="col-chg" style="color:var(--text-light); text-transform:uppercase;">CHG</div>
            <div class="col-id" style="color:var(--text-light); text-transform:uppercase;">NIP</div>
            <div class="col-name" style="color:var(--text-light); text-transform:uppercase;">NAMA PEGAWAI</div>
            <div class="col-pos" style="color:var(--text-light); text-transform:uppercase;">POSISI</div>
            <div class="col-area" style="color:var(--text-light); text-transform:uppercase;">CABANG-AREA</div>
            <div class="col-score" style="color:var(--text-light); text-transform:uppercase;">{label_utama}</div>
            <div class="col-growth" style="color:var(--text-light); text-transform:uppercase;">GROWTH</div>
        </div>
        """
        c_head, c_btn_head = st.columns([8.5, 1.5])
        with c_head: st.markdown(header_html, unsafe_allow_html=True)
        with c_btn_head: st.markdown("<div style='font-size:0.75rem; font-weight:800; color:var(--text-light); text-transform:uppercase; text-align:center; margin-top:10px;'>AKSI</div>", unsafe_allow_html=True)

        page_size = 50
        total_pages = max(1, int(math.ceil(total_pegawai / page_size)))
        start = (st.session_state.page_num - 1) * page_size
        dfp_page = dfp_all.iloc[start:start+page_size]

        for idx, (_, r) in enumerate(dfp_page.iterrows(), start=start+1):
            bg_col, txt_col = get_f1_style_global(r.get('area', ''))
            change_badge = get_table_rank_change_html(r.get('rank_change', 0), r.get('score_utama_base', 0))
            
            # Format Cabang - Area
            area_str = get_area_name_global(r.get('area', ''))
            cabang_area_str = f"{r.get('unit', '-')} - AREA {area_str}"
            
            # Render Baris Data Pegawai
            row_html = f"""
            <div class="table-row" style="background-color:{bg_col};">
                <div class="col-rank" style="color:{txt_col};">#{idx}</div>
                <div class="col-chg">{change_badge}</div>
                <div class="col-id" style="color:{txt_col};">{r['nip']}</div>
                <div class="col-name" style="color:{txt_col};">{r.get('nama','-')}</div>
                <div class="col-pos" style="color:{txt_col};">{r.get('posisi','-')}</div>
                <div class="col-area" style="color:{txt_col};">{cabang_area_str}</div>
                <div class="col-score" style="color:{txt_col};">{fmt_fungsi(r['score_utama'])}</div>
                <div class="col-growth">{fmt_growth(r['score_utama'], r['score_utama_base'], fmt_fungsi)}</div>
            </div>
            """
            
            c_data, c_btn = st.columns([8.5, 1.5], vertical_alignment="center")
            with c_data: st.markdown(row_html, unsafe_allow_html=True)
            with c_btn:
                if st.button("Detail", key=f"btn_pg_{r['nip']}", use_container_width=True):
                    st.session_state.view = "detail_pegawai"; st.session_state.detail_nip = r['nip']; st.rerun()

        st.markdown("<div style='margin-top: 24px;'></div>", unsafe_allow_html=True)
        b1, b2, b3 = st.columns([1,2,1])
        if b1.button("⬅️ Sebelumnya", use_container_width=True):
            st.session_state.page_num = st.session_state.page_num - 1 if st.session_state.page_num > 1 else total_pages
            st.rerun()
        b2.markdown(f"<div style='text-align:center; padding-top:8px; font-weight:600; font-size:0.9rem; color:var(--text-muted);'>Halaman <span style='color:var(--f1-dark);'>{st.session_state.page_num}</span> dari {total_pages}</div>", unsafe_allow_html=True)
        if b3.button("Selanjutnya ➡️", use_container_width=True):
            st.session_state.page_num = st.session_state.page_num + 1 if st.session_state.page_num < total_pages else 1
            st.rerun()
    else:
        st.warning("Tidak ada pegawai untuk filter ini.")
# ---------------------------
# 13. ADMIN PANEL (UPSERT LOGIC)
# ---------------------------
if st.session_state.show_update_panel and st.session_state.get("is_admin", False):
    st.markdown("<hr style='border-color:var(--border)'>", unsafe_allow_html=True)
    with st.expander("⚙️ Admin Panel (Uploader & Setting)", expanded=True):
        st.markdown("#### 📊 Rekapitulasi Pengunjung")
        conn = sqlite3.connect(DB_PATH)
        df_summary = pd.read_sql_query("SELECT nip AS NIP, nama AS Nama, COUNT(*) AS 'Total Kunjungan', MAX(waktu) AS 'Kunjungan Terakhir' FROM access_log GROUP BY nip, nama ORDER BY 'Total Kunjungan' DESC", conn)
        st.dataframe(df_summary, use_container_width=True, hide_index=True)
        conn.close()
        st.markdown("<hr style='border-color:var(--border)'>", unsafe_allow_html=True)

        st.markdown("#### 📤 Upload Data Master")
        upload_type = st.radio("Pilih Jenis Data yang Di-upload:", options=["Data Berjalan (Update Current Data)", "Data Baseline (Posisi 31 Maret - Base Growth)"], help="Pilih Baseline jika Anda ingin mengatur titik awal perhitungan persentase kenaikan (Growth).")
        upload_file = st.file_uploader("Upload Excel (.xlsx/.xls) - GMM LIVIN, GMM MERCHANT, GMM TRANSAKSI", type=['xlsx','xls'])
        
        if upload_file:
            file_bytes = io.BytesIO(upload_file.getvalue())
            xls = pd.read_excel(file_bytes, sheet_name=None, dtype=str)
            st.success(f"Membaca {len(xls)} sheet: {', '.join(xls.keys())}")
            
            if st.button("Mulai Proses Data", type="primary"):
                conn = sqlite3.connect(DB_PATH, timeout=30.0)
                conn.execute("PRAGMA journal_mode=WAL;")
                cur = conn.cursor()
                
                try:
                    master_data = {}
                    def find_col(df, aliases):
                        lc_cols = [str(c).lower().strip() for c in df.columns]
                        for a in aliases:
                            if a.lower() in lc_cols: return df.columns[lc_cols.index(a.lower())]
                        return None
                    
                    def safe_get_num(row, col): return normalize_val(row[col]) if col is not None else 0

                    if "GMM LIVIN" in xls:
                        df_l = xls["GMM LIVIN"]
                        c_nip, c_nama, c_kode = find_col(df_l, ['nip']), find_col(df_l, ['nama','employee name']), find_col(df_l, ['kode cabang','kode_cabang'])
                        c_unit, c_area, c_kelas, c_posisi = find_col(df_l, ['nama cabang', 'nama_cabang', 'cabang', 'unit']), find_col(df_l, ['area', 'wilayah']), find_col(df_l, ['kelas cabang', 'kelas']), find_col(df_l, ['posisi', 'unit kerja'])
                        c_cif_akuisisi, c_cif_setor, c_end_balance, c_rata_rata = find_col(df_l, ['cif akuisisi','cif']), find_col(df_l, ['cif setor']), find_col(df_l, ['end_balance','end balance']), find_col(df_l, ['rata-rata','rata rata'])
                        c_cif_trx, c_frek_cif = find_col(df_l, ['cif_sudah_transaksi','cif sudah transaksi']), find_col(df_l, ['frek dari cif akuisisi'])

                        if c_nip and c_nama:
                            for _, r in df_l.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                master_data[nip] = {
                                    'nip': nip, 'nama': str(r[c_nama]).strip(),
                                    'kode_cabang': str(r[c_kode]).strip() if c_kode else '', 'unit': str(r[c_unit]).strip() if c_unit else '',
                                    'area': str(r[c_area]).strip() if c_area else '', 'kelas_cabang': str(r[c_kelas]).strip() if c_kelas else '',
                                    'posisi': str(r[c_posisi]).strip() if c_posisi else '',
                                    'cif_akuisisi': safe_get_num(r, c_cif_akuisisi), 'cif_setor': safe_get_num(r, c_cif_setor),
                                    'end_balance': safe_get_num(r, c_end_balance), 'rata_rata': safe_get_num(r, c_rata_rata),
                                    'cif_sudah_transaksi': safe_get_num(r, c_cif_trx), 'frek_dari_cif_akuisisi': safe_get_num(r, c_frek_cif),
                                }

                    if "GMM MERCHANT" in xls:
                        df_m = xls["GMM MERCHANT"]
                        c_nip = find_col(df_m, ['nip'])
                        if c_nip:
                            for _, r in df_m.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                if nip not in master_data: master_data[nip] = {'nip': nip, 'nama': str(r[find_col(df_m, ['nama pegawai','nama'])]).strip()}
                                master_data[nip]['total_referral_livin'] = normalize_val(r[find_col(df_m, ['total referral livin'])])
                                master_data[nip]['total_referral_edc'] = normalize_val(r[find_col(df_m, ['total referral edc'])])

                    if "GMM TRANSAKSI" in xls:
                        df_t = xls["GMM TRANSAKSI"]
                        c_nip = find_col(df_t, ['nip'])
                        if c_nip:
                            for _, r in df_t.iterrows():
                                nip = str(r[c_nip]).strip()
                                if nip == 'nan' or not nip: continue
                                if nip not in master_data: master_data[nip] = {'nip': nip, 'nama': str(r[find_col(df_t, ['nama pegawai','nama'])]).strip()}
                                master_data[nip]['total_poin_transaksi'] = normalize_val(r[find_col(df_t, ['total poin transaksi'])])
                                master_data[nip]['poin_on_us'] = normalize_val(r[find_col(df_t, ['poin on us'])])
                                master_data[nip]['poin_off_us'] = normalize_val(r[find_col(df_t, ['poin off us'])])
                                master_data[nip]['frek_on_us'] = normalize_val(r[find_col(df_t, ['frek on us'])])
                                master_data[nip]['frek_off_us'] = normalize_val(r[find_col(df_t, ['frek off us'])])
                                master_data[nip]['pct_on_us'] = normalize_val(r[find_col(df_t, ['pct on us'])])

                    inserted = 0
                    is_base = "Baseline" in upload_type
                    if not is_base: cur.execute("UPDATE pegawai SET is_active = 0")
                    
                    for nip, d in master_data.items():
                        d.setdefault('kode_cabang', ''); d.setdefault('unit', ''); d.setdefault('area', ''); d.setdefault('kelas_cabang', ''); d.setdefault('posisi', '')
                        
                        if d['kode_cabang']:
                            cur.execute("""
                                INSERT INTO cabang (kode_cabang, unit, area, kelas_cabang) VALUES (?, ?, ?, ?)
                                ON CONFLICT(kode_cabang) DO UPDATE SET unit=excluded.unit, area=excluded.area, kelas_cabang=excluded.kelas_cabang
                            """, (d['kode_cabang'], d.get('unit'), d.get('area'), d.get('kelas_cabang')))
                        
                        if is_base:
                            cur.execute("""
                                INSERT INTO pegawai (nip, nama, kode_cabang, unit, area, posisi, end_balance_base, cif_akuisisi_base, cif_setor_base, cif_sudah_transaksi_base, rata_rata_base, frek_dari_cif_akuisisi_base, total_referral_livin_base, total_referral_edc_base, total_poin_transaksi_base, poin_on_us_base, poin_off_us_base, frek_on_us_base, frek_off_us_base, pct_on_us_base)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON CONFLICT(nip) DO UPDATE SET
                                    nama=excluded.nama, kode_cabang=excluded.kode_cabang, unit=excluded.unit, area=excluded.area, posisi=excluded.posisi,
                                    end_balance_base=excluded.end_balance_base, cif_akuisisi_base=excluded.cif_akuisisi_base, cif_setor_base=excluded.cif_setor_base, 
                                    cif_sudah_transaksi_base=excluded.cif_sudah_transaksi_base, rata_rata_base=excluded.rata_rata_base, frek_dari_cif_akuisisi_base=excluded.frek_dari_cif_akuisisi_base,
                                    total_referral_livin_base=excluded.total_referral_livin_base, total_referral_edc_base=excluded.total_referral_edc_base, 
                                    total_poin_transaksi_base=excluded.total_poin_transaksi_base, poin_on_us_base=excluded.poin_on_us_base, poin_off_us_base=excluded.poin_off_us_base, 
                                    frek_on_us_base=excluded.frek_on_us_base, frek_off_us_base=excluded.pct_on_us_base
                            """, (d['nip'], d['nama'], d['kode_cabang'], d.get('unit'), d.get('area'), d.get('posisi'), 
                                  d.get('end_balance',0), d.get('cif_akuisisi',0), d.get('cif_setor',0), d.get('cif_sudah_transaksi',0), d.get('rata_rata',0), d.get('frek_dari_cif_akuisisi',0),
                                  d.get('total_referral_livin',0), d.get('total_referral_edc',0), d.get('total_poin_transaksi',0), d.get('poin_on_us',0), d.get('poin_off_us',0), d.get('frek_on_us',0), d.get('frek_off_us',0), d.get('pct_on_us',0)))
                        else:
                            cur.execute("""
                                INSERT INTO pegawai (nip, nama, kode_cabang, unit, area, posisi, end_balance, cif_akuisisi, cif_setor, cif_sudah_transaksi, rata_rata, frek_dari_cif_akuisisi, total_referral_livin, total_referral_edc, total_poin_transaksi, poin_on_us, poin_off_us, frek_on_us, frek_off_us, pct_on_us, is_active)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                                ON CONFLICT(nip) DO UPDATE SET
                                    nama=excluded.nama, kode_cabang=excluded.kode_cabang, unit=excluded.unit, area=excluded.area, posisi=excluded.posisi,
                                    end_balance=excluded.end_balance, cif_akuisisi=excluded.cif_akuisisi, cif_setor=excluded.cif_setor, 
                                    cif_sudah_transaksi=excluded.cif_sudah_transaksi, rata_rata=excluded.rata_rata, frek_dari_cif_akuisisi=excluded.frek_dari_cif_akuisisi,
                                    total_referral_livin=excluded.total_referral_livin, total_referral_edc=excluded.total_referral_edc, 
                                    total_poin_transaksi=excluded.total_poin_transaksi, poin_on_us=excluded.poin_on_us, poin_off_us=excluded.poin_off_us, 
                                    frek_on_us=excluded.frek_on_us, frek_off_us=excluded.frek_off_us, pct_on_us=excluded.pct_on_us, is_active=1
                            """, (d['nip'], d['nama'], d['kode_cabang'], d.get('unit'), d.get('area'), d.get('posisi'), 
                                  d.get('end_balance',0), d.get('cif_akuisisi',0), d.get('cif_setor',0), d.get('cif_sudah_transaksi',0), d.get('rata_rata',0), d.get('frek_dari_cif_akuisisi',0),
                                  d.get('total_referral_livin',0), d.get('total_referral_edc',0), d.get('total_poin_transaksi',0), d.get('poin_on_us',0), d.get('poin_off_us',0), d.get('frek_on_us',0), d.get('frek_off_us',0), d.get('pct_on_us',0)))
                        inserted += 1

                    conn.commit()
                    st.success(f"Selesai! Berhasil update {inserted} baris {upload_type.split(' ')[1]}.")
                except Exception as e:
                    import traceback
                    st.error(f"Pesan Error: {e}"); st.code(traceback.format_exc(), language="python")
                finally: conn.close()

        if st.button("⚠️ Hapus Seluruh Database (Hard Reset)"):
            conn = sqlite3.connect(DB_PATH)
            conn.execute("DROP TABLE IF EXISTS pegawai")
            conn.execute("DROP TABLE IF EXISTS cabang")
            conn.commit(); conn.close()
            st.cache_resource.clear() 
            init_db()
            st.success("Database berhasil dikosongkan. Halaman akan dimuat ulang...")
            import time; time.sleep(1); st.rerun()
