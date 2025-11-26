# /mnt/data/leaderboardv4.py
import sqlite3
import pandas as pd
import streamlit as st
import io
import altair as alt
import os
import random
import math

DB_PATH = "ycc_leaderboard.db"

# ---------------------------
# Init DB & queries
# ---------------------------
def init_db():
    conn = sqlite3.connect(DB_PATH)
    cur = conn.cursor()
    cur.execute("""
    CREATE TABLE IF NOT EXISTS cabang (
        kode_cabang TEXT PRIMARY KEY,
        unit TEXT,
        area TEXT,
        nama_cabang TEXT
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
        FOREIGN KEY (kode_cabang) REFERENCES cabang(kode_cabang)
    )
    """)
    conn.commit()
    conn.close()

def get_cabang_leaderboard():
    conn = sqlite3.connect(DB_PATH)
    df = pd.read_sql_query("""
        SELECT k.kode_cabang,
               COALESCE(c.unit, k.kode_cabang) AS unit,
               COALESCE(c.area, '(Unknown)') AS area,
               IFNULL(SUM(p.end_balance),0) AS total_balance,
               IFNULL(COUNT(p.nip),0) AS jumlah_pegawai,
               IFNULL(AVG(p.end_balance),0) AS rata_rata_saldo,
               IFNULL(SUM(p.cif_akuisisi),0) AS total_cif
        FROM (
          SELECT kode_cabang FROM cabang
          UNION
          SELECT DISTINCT kode_cabang FROM pegawai
        ) k
        LEFT JOIN cabang c ON k.kode_cabang = c.kode_cabang
        LEFT JOIN pegawai p ON k.kode_cabang = p.kode_cabang
        GROUP BY k.kode_cabang
        ORDER BY total_balance DESC
    """, conn)
    conn.close()
    return df

def get_pegawai(kode):
    conn = sqlite3.connect(DB_PATH)
    if kode is None or kode == "ALL":
        df = pd.read_sql_query("""
            SELECT nip, nama, kode_cabang, unit, cif_akuisisi, pct_akuisisi,
       cif_setor, cif_sudah_transaksi, frek_dari_cif_akuisisi,
       sv_dari_cif_akuisisi_jt, IFNULL(end_balance,0) AS end_balance,
       IFNULL(rata_rata,0) AS rata_rata, area, nama_cabang, posisi, avatar_url
            FROM pegawai
            ORDER BY end_balance DESC
        """, conn)
    else:
        # check existing kode_cabang
        df_cabang = pd.read_sql_query("SELECT kode_cabang FROM cabang", conn)
        kode_list = df_cabang['kode_cabang'].tolist()
        if kode in kode_list:
            df = pd.read_sql_query("""
                SELECT nip, nama, kode_cabang, unit, cif_akuisisi, pct_akuisisi,
       cif_setor, cif_sudah_transaksi, frek_dari_cif_akuisisi,
       sv_dari_cif_akuisisi_jt, IFNULL(end_balance,0) AS end_balance,
       IFNULL(rata_rata,0) AS rata_rata, area, nama_cabang, posisi, avatar_url
                FROM pegawai
                WHERE kode_cabang = ?
                ORDER BY end_balance DESC
            """, conn, params=(kode,))
        else:
            q = """
            SELECT nip, nama, kode_cabang, unit, cif_akuisisi, pct_akuisisi,
       cif_setor, cif_sudah_transaksi, frek_dari_cif_akuisisi,
       sv_dari_cif_akuisisi_jt, IFNULL(end_balance,0) AS end_balance,
       IFNULL(rata_rata,0) AS rata_rata, area, nama_cabang, posisi, avatar_url
            FROM pegawai
            WHERE LOWER(area) = LOWER(?)
            ORDER BY end_balance DESC
            """
            df = pd.read_sql_query(q, conn, params=(kode,))
    conn.close()
    return df

# ---------------------------
# UI helpers
# ---------------------------
FALLBACK_AVATAR = "https://images.pexels.com/photos/220453/pexels-photo-220453.jpeg"

def format_rp(value):
    try:
        v = int(round((value)))
    except:
        v = 0
    s = f"Rp {v:,}".replace(",", ".")
    return s + "jt"


import re

def normalize_end_balance(x):
    """
    Semua nilai End_Balance dianggap satuan JUTA.
    Contoh:
    - Rp0.10     -> 0.10 * 1_000_000 = 100000
    - Rp872.29   -> 872.29 * 1_000_000
    - 1.34       -> 1.34 * 1_000_000
    """

    if x is None:
        return 0

    s = str(x).strip()
    # hapus "Rp", spasi, titik pemisah ribuan
    s = re.sub(r'([Rr][Pp]\s*)', '', s)
    s = s.replace('.', '').replace(',', '.')

    # sisakan angka & titik
    s = re.sub(r'[^0-9\.]', '', s)
    if s == '' or s == '.':
        return 0

    try:
        val = (s)
    except:
        return 0

    # konversi juta → rupiah
    return int(round(val * 1_000_000))

def df_to_csv_bytes(df: pd.DataFrame):
    towrite = io.StringIO()
    df.to_csv(towrite, index=False)
    return towrite.getvalue().encode('utf-8')

# ---------------------------
# CSS & Logo adjustments (palet biru)
# ---------------------------
# gunakan path lokal file gambar yang kamu upload (developer note: akan di-convert menjadi url)
LOGO_PATH = "https://github.com/Cyberius8/EDA-Mandiri/blob/main/R11GMM.jpg?raw=true"

ENHANCED_CSS = rf"""
<style>
@import url('https://fonts.googleapis.com/css2?family=Inter:wght@300;400;600;700;800&display=swap');
:root{{
  --bg1: #061526;
  --bg2: #0b2b46;
  --accent: #1fb6ff;
  --accent-2: #00d4ff;
  --muted: rgba(255,255,255,0.72);
  --text: rgba(255,255,255,0.96);
  --glass: rgba(255,255,255,0.03);
  --card-radius: 12px;
}}
body, .stApp {{
  font-family: 'Inter', system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial;
  background: linear-gradient(180deg,var(--bg1),var(--bg2)) !important;
  color: var(--text) !important;
}}
.row-entry{{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:12px 10px;
  border-bottom:1px solid rgba(255,255,255,0.02);
  margin-bottom:8px;
}}
.row-left{{
  display:flex;
  align-items:center;
  gap:12px;
  min-width:0;
}}
.row-meta{{
  display:flex;
  flex-direction:column;
  min-width:0;
}}
.unit{{
  font-weight:700;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}}
.subcode{{
  color:var(--muted);
  font-size:13px;
  margin-top:4px;
}}
.detail-link{{
  background: rgba(255,255,255,0.04);
  color: var(--text);
  padding:8px 12px;
  border-radius:10px;
  text-decoration:none;
  border:1px solid rgba(255,255,255,0.06);
  font-weight:700;
  box-shadow: 0 6px 18px rgba(0,0,0,0.18);
}}
@media (max-width:420px){{
  .row-entry{{ gap:8px; padding:10px 8px; }}
  .unit{{ white-space:normal; }}
}}
.header-row {{
  display:flex; align-items:center; gap:12px; width:100%;
}}
.header-left {{ display:flex; align-items:center; gap:12px; }}
.logo-img {{ width:256px;height:256px;border-radius:12px;object-fit:cover;border:2px solid rgba(255,255,255,0.06);box-shadow:0 10px 28px rgba(0,0,0,0.6); }}

.header-center {{
  display:flex; flex-direction:column; align-items:center; justify-content:center; gap:6px;
  flex:1;
}}
.title-pill {{
  background: rgba(255,255,255,1);
  color: #041827;
  padding:14px 28px;
  border-radius:28px;
  font-weight:800;
  font-size:28px;
  box-shadow: 0 12px 30px rgba(0,0,0,0.35);
  display:inline-block;
  border: 4px solid rgba(0,0,0,0.06);
}}
.subtitle-small {{ color:var(--muted); font-weight:600; font-size:13px; }}
.header-right {{ display:flex; gap:10px; align-items:center; justify-content:flex-end; }}

.action-pill {{
  background: rgba(255,255,255,0.92);
  color:#041827;
  padding:10px 14px;
  border-radius:14px;
  font-weight:700;
  border:1px solid rgba(0,0,0,0.06);
  box-shadow:0 6px 18px rgba(0,0,0,0.28);
}}

.leaderboard-card {{ background: linear-gradient(135deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01)); padding:12px; border-radius:12px; border:1px solid rgba(255,255,255,0.04); }}
.field {{
  width:100%; background: linear-gradient(180deg,#0b3b26,#063a2d); border-radius:16px; padding:18px; position:relative; overflow:hidden; box-shadow: inset 0 0 80px rgba(0,0,0,0.5);
  background-image: radial-gradient(circle at 50% 10%, rgba(255,255,255,0.03), transparent 10%);
}}
.pitch-row {{ display:flex; justify-content:center; gap:28px; margin:18px 0; }}
.player-badge {{
  width:140px; border-radius:12px; background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(0,0,0,0.18)); padding:8px; text-align:center; border:1px solid rgba(255,255,255,0.04);
}}
.player-avatar {{ width:56px;height:56px;border-radius:10px;object-fit:cover;border:2px solid rgba(255,255,255,0.06); display:block; margin:0 auto 8px; }}
.player-name {{ font-weight:700; font-size:14px; color:var(--text) }}
.player-meta {{ color:var(--muted); font-size:12px }}
.small-muted {{ color:var(--muted); font-size:13px; }}
@media (max-width: 720px) {{
  .player-badge {{ width:110px; }}
  .player-avatar {{ width:48px; height:48px; }}
  .title-pill {{ font-size:20px; padding:10px 18px; }}
}}

.kpi {{
  border-radius: 16px; padding:16px; color:white;
  background: linear-gradient(135deg, #0ea5e9 0%, #1d4ed8 45%, #0f172a 100%);
  box-shadow: 0 8px 24px rgba(2,6,23,.35);
}}
.kpi .title {{font-size:0.95rem; font-weight:700; opacity:.9; margin:0}}
.kpi .val   {{font-size:1.9rem; font-weight:900; margin-top:4px; line-height:1.2}}
.kpi .sub   {{font-size:.9rem; opacity:.85; margin-top:6px}}

.kpi.green {{ background: linear-gradient(135deg, #22c55e 0%, #16a34a 40%, #064e3b 100%); }}
.kpi.purple {{ background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 40%, #1e1b4b 100%); }}
hr {{border: none; height:1px; background: linear-gradient(90deg, transparent, rgba(148,163,184,.35), transparent);}} 

/* Medal badges */
.medal {{
  font-weight:700;
  font-size:0.85rem;
  padding:6px 10px;
  border-radius:12px;
  display:inline-block;
  margin-bottom:6px;
  box-shadow: 0 4px 10px rgba(2,6,23,0.12);
}}
.medal.gold {{
  background: linear-gradient(135deg, #FFD700 0%, #FFC107 60%);
  color: #111;
}}
.medal.silver {{
  background: linear-gradient(135deg, #e9eef2 0%, #cfd8dc 60%);
  color: #111;
}}
.medal.bronze {{
  background: linear-gradient(135deg, #cd7f32 0%, #b4692b 60%);
  color: #111;
}}

/* existing leaderboard-card style (jaga konsistensi jika sudah ada) */
.leaderboard-card {{
  background: rgba(255,255,255,0.03);
  border-radius: 12px;
  padding: 12px;
  color: #e6eef8;
}}
.small-muted {{ opacity:0.75; font-size:0.9rem; color:inherit }}

<!-- CSS: paste sebelum loop (mis. di awal page) -->
/* row card */
.row-card {{
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
  border-radius: 12px;
  padding: 14px;
  margin-bottom: 12px;
  display: flex;                  /* wajib */
  justify-content: space-between; /* kiri — kanan */
  align-items: center;            /* vertikal rapi */
  box-shadow: 0 6px 18px rgba(2,6,23,0.25);
  border: 1px solid rgba(255,255,255,0.03);
}}


/* left area: rank + meta */
.row-left {{
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1;              /* BIAR KONTEN KIRI MELEBAR */
}}


/* Rank badge */
.rank-badge {{
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:18px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}}

/* special looks for top 3 */
.rank-badge.top1 {{ background: linear-gradient(135deg,#FFD700,#FFC107); color:#3A2C00; border-radius:14px; }}
.rank-badge.top2 {{ background: linear-gradient(135deg,#cfcfcf,#bfc4c8); color:#3A3A3A; border-radius:14px; }}
.rank-badge.top3 {{ background: linear-gradient(135deg,#cd7f32,#b4692b); color:#3C2500; border-radius:14px; }}

/* meta text */
.row-meta {{ display:flex; flex-direction:column; gap:4px; color:inherit; }}
.row-meta .unit {{ font-weight:800; font-size:0.95rem; }}
.row-meta .info {{ font-size:0.85rem; opacity:0.8; }}
.row-meta .name {{
  font-weight:800;
  font-size:0.95rem;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  color: #e6f2ff;
}}

.row-meta .small-muted {{
  font-size:0.85rem;
  opacity:0.85;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
  color: #bcd1e6;
}}

.row-right {{
  flex:0 0 auto;
  margin-left:12px;
  text-align:right;
  color:#e6f2ff;
  font-weight:800;
}}

/* right side link */
.detail-link {{
  display:inline-block;
  padding:8px 12px;
  border-radius:10px;
  background: linear-gradient(180deg, rgba(255,255,255,0.03), rgba(255,255,255,0.02));
  border: 1px solid rgba(255,255,255,0.06);
  color:var(--accent);
  text-decoration:none;
  font-weight:700;
  box-shadow: 0 6px 14px rgba(2,6,23,0.12);
}}

/* small muted under unit (for area/kode/values) */
.small-muted {{ font-size:0.85rem; opacity:0.8; }}
/* container card: kiri-kanan di satu baris */
.row-card {{
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 14px;
  margin-bottom: 12px;
  border-radius: 12px;
  background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.01));
  border: 1px solid rgba(255,255,255,0.04);
  box-shadow: 0 6px 18px rgba(2,6,23,0.18);
  gap: 12px;
}}

/* kiri melebar, tapi tidak menekan tombol kanan */
.row-left {{
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 auto;   /* grow allowed, shrink allowed */
  min-width: 0;     /* important: allow children to shrink instead of pushing sibling */
}}
.stat-container {{
    display: flex;
    gap: 16px;
    margin-top: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
}}

.stat-card {{
    background: linear-gradient(135deg, #0F172A, #1E293B);
    padding: 14px 18px;
    border-radius: 14px;
    min-width: 180px;
    color: #e6eef8;
    box-shadow: 0 4px 12px rgba(0,0,0,0.28);
    flex: 1;
}}

.stat-title {{
    font-size: 0.85rem;
    opacity: 0.75;
    margin-bottom: 6px;
}}

.stat-value {{
    font-size: 1.4rem;
    font-weight: 800;
    margin-bottom: 2px;
}}

.stat-extra {{
    font-size: 0.85rem;
    opacity: 0.5;
}}

/* kanan tidak menyusut (tetap terlihat) */
.row-right {{
  flex: 0 0 auto;
  margin-left: 12px;
}}

/* badge rank */
.rank-badge {{
    width: 48px;
    height: 48px;
    border-radius: 14px;
    display:flex;
    align-items:center;
    justify-content:center;
    font-weight:700;
    font-size:18px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.25);
}}

/* teks meta supaya memotong bila space sempit */
.row-meta {{
  display:flex;
  flex-direction:column;
  gap:4px;
  min-width:0; /* IMPORTANT untuk mencegah overflow */
}}
.row-meta .unit {{ font-weight:800; font-size:0.95rem; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }}
.row-meta .info {{ font-size:0.85rem; opacity:0.8; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }}

/* tombol */
.detail-link {{
  display:inline-block;
  padding:8px 14px;
  border-radius:10px;
  background: rgba(255,255,255,0.04);
  border: 1px solid rgba(255,255,255,0.08);
  color: var(--accent);
  text-decoration:none;
  font-weight:700;
  white-space:nowrap;
}}

.header-wrapper {{
    display: flex;
    flex-direction: column;
    align-items: center;
    margin-top: 10px;
    margin-bottom: 10px;
}}

/* logo */
.header-logo {{
    width: 90px;
    height: 90px;
    border-radius: 50%;
    object-fit: cover;
    box-shadow: 0 4px 16px rgba(0,0,0,0.25);
}}

/* title */
.header-title {{
    margin-top: 10px;
    font-size: 1.8rem;
    font-weight: 900;
    padding: 12px 26px;
    background: white;
    color: #07122a;
    border-radius: 50px;
    text-align: center;
    box-shadow: 0 8px 22px rgba(255,255,255,0.2);
}}

/* subtitle */
.header-subtitle {{
    margin-top: 4px;
    font-size: 0.9rem;
    opacity: 0.8;
    color: #cfd8e3;
}}


</style>
"""

# ---------------------------
# INIT
# ---------------------------
init_db()

if "view" not in st.session_state:
    st.session_state.view = "cabang"
if "kode" not in st.session_state:
    st.session_state.kode = None
if "page_num" not in st.session_state:
    st.session_state.page_num = 1
if "page_size" not in st.session_state:
    st.session_state.page_size = 10
if "is_admin" not in st.session_state:
    st.session_state.is_admin = False
if "show_update_panel" not in st.session_state:
    st.session_state.show_update_panel = False

st.markdown(ENHANCED_CSS, unsafe_allow_html=True)

# Header with logo
# gunakan tiga kolom agar responsive dan judul bisa terpusat
# ---------------------------
# Header with centered title & buttons below
# ---------------------------
st.markdown(f"""
    <div class='header-center'>
      <img src='{LOGO_PATH}' class='logo-img'/>
    </div>
    """, unsafe_allow_html=True)

# Layout: logo kiri — title center — (kosong kanan)
col_l, col_c, col_r = st.columns([1,4,1])

# ---------------- Logo kiri ----------------
with col_l:
    st.markdown("")

# ---------------- Title center + Buttons bawah ----------------
with col_c:
    st.markdown(f"""
    <div class='header-center'>
      <div class='title-pill'>GMM RACEBOARD</div>
      <div class='subtitle-small'>25 November 2025</div>
    </div>
    """, unsafe_allow_html=True)

    # Tombol dipindah ke bawah title
    st.markdown("<div style='height:12px'></div>", unsafe_allow_html=True)

    cbtn1, cbtn2= st.columns([1,1])
    with cbtn1:
        if st.button("Leaderboard Cabang"):
            st.session_state.view = "cabang"
            st.session_state.kode = None
            st.session_state.page_num = 1
            st.query_params.clear()
            st.rerun()

    with cbtn2:
        if st.button("Leaderboard Pegawai"):
            st.session_state.view = "pegawai"
            st.session_state.kode = "ALL"
            st.rerun()

    # with cbtn3:
    #     if st.button(""):
    #         st.session_state.show_update_panel = not st.session_state.show_update_panel
    #         st.rerun()


# ---------------------------
# Update / Import panel (admin)
# ---------------------------
if st.session_state.show_update_panel:
    st.markdown("<hr style='border-color:rgba(255,255,255,0.04)'>", unsafe_allow_html=True)
    st.markdown("Import hanya oleh admin.")
    with st.expander("Admin — unlock import"):
        pwd = st.text_input("Password admin", type="password")
        if st.button("Unlock Import"):
            # simple default password (production: ganti dengan st.secrets)
            if pwd == os.environ.get("ADMIN_PASSWORD", "changeme123"):
                st.session_state.is_admin = True
                st.success("Admin unlocked")
            else:
                st.error("Password salah.")
    if st.session_state.is_admin:
        upload_file = st.file_uploader("Upload Excel (.xlsx/.xls)", type=['xlsx','xls'])
        if upload_file:
            try:
                df_raw = pd.read_excel(upload_file, sheet_name=0, dtype=str)
            except Exception as e:
                st.error(f"Gagal baca file: {e}")
                df_raw = None
            if df_raw is not None:
                # mapping tolerant
                mapping_candidates = {
                    'nip': ['nip','n i p','no nip','no. nip'],
                    'nama': ['nama','name','employee name'],
                    'kode_cabang': ['kode cabang','kode_cabang','kode','kodecabang'],
                    'unit': ['unit','nama cabang','nama_cabang','branch name','cabang'],
                    'cif_akuisisi': ['cif akuisisi','#cif akuisisi','cif_akuisisi','cif'],
                    'pct_akuisisi': ['% akuisisi','pct akuisisi','persen akuisisi','%_akuisisi'],
                    'cif_setor': ['#cif setor (min 100rb)','cif_setor','cif setor','#cif_setor'],
                    'pct_setor_akuisisi': ['% setor / akuisisi','% setor/ akuisisi','%setor/akuisisi'],
                    'cif_sudah_transaksi': ['# cif sudah transaksi','cif_sudah_transaksi'],
                    'pct_transaksi_setor': ['% transaksi / setor','% transaksi/ setor','%transaksi/setor'],
                    'frek_dari_cif_akuisisi': ['frek dari cif yang diakuisisi','frek dari cif','frekuensi dari cif'],
                    'sv_dari_cif_akuisisi_jt': ['sv dari cif yang diakuisisi (jt)','sv dari cif','sv_cif_jt'],
                    'end_balance': ['end_balance','rata - rata saldo tabungan (jt)','end balance','end_balance (jt)'],
                    'rata_rata': ['rata-rata','rata rata','rata_rata'],
                    'area': ['area','wilayah','region'],
                    'nama_cabang': ['nama cabang','nama_cabang'],
                    'posisi': ['unit kerja','posisi','unit kerja pegawai']
                }

                lc_cols = [c.lower().strip() for c in df_raw.columns]
                found = {}
                for key, cands in mapping_candidates.items():
                    for cand in cands:
                        if cand in lc_cols:
                            found[key] = df_raw.columns[lc_cols.index(cand)]
                            break
                required = ['nip','nama','kode_cabang','end_balance']
                missing = [r for r in required if r not in found]
                if missing:
                    st.error("File tidak mengandung kolom wajib: " + ", ".join(missing))
                else:
                    df_ins = pd.DataFrame()
                    df_ins['nip'] = df_raw[found['nip']].astype(str).str.strip()
                    df_ins['nama'] = df_raw[found['nama']].astype(str).str.strip()
                    df_ins['kode_cabang'] = df_raw[found['kode_cabang']].astype(str).str.strip()
                    df_ins['unit'] = df_raw[found['unit']].astype(str).str.strip() if 'unit' in found else ''
                    df_ins['area'] = df_raw[found['area']].astype(str).str.strip() if 'area' in found else ''
                    df_ins['nama_cabang'] = df_raw[found['nama_cabang']].astype(str).str.strip() if 'nama_cabang' in found else ''
                    df_ins['posisi'] = df_raw[found['posisi']].astype(str).str.strip() if 'posisi' in found else ''
                    df_ins['cif_akuisisi'] = df_raw[found['cif_akuisisi']].astype(str).str.strip() if 'cif_akuisisi' in found else 0
                    df_ins['pct_akuisisi'] = df_raw[found['pct_akuisisi']].astype(str).str.strip() if 'pct_akuisisi' in found else 0                             
                    df_ins['cif_setor'] = df_raw[found['cif_setor']].astype(str).str.strip() if 'cif_setor' in found else 0
                    df_ins['pct_setor_akuisisi'] = df_raw[found['pct_setor_akuisisi']].astype(str).str.strip() if 'pct_setor_akuisisi' in found else 0
                    df_ins['cif_sudah_transaksi'] = df_raw[found['cif_sudah_transaksi']].astype(str).str.strip() if 'cif_sudah_transaksi' in found else 0
                    df_ins['pct_transaksi_setor'] = df_raw[found['pct_transaksi_setor']].astype(str).str.strip() if 'pct_transaksi_setor' in found else 0
                    df_ins['frek_dari_cif_akuisisi'] = df_raw[found['frek_dari_cif_akuisisi']].astype(str).str.strip() if 'frek_dari_cif_akuisisi' in found else 0
                    df_ins['sv_dari_cif_akuisisi_jt'] = df_raw[found['sv_dari_cif_akuisisi_jt']].astype(str).str.strip() if 'sv_dari_cif_akuisisi_jt' in found else 0
                    df_ins['end_balance'] = df_raw[found['end_balance']].astype(str).str.strip() if 'end_balance' in found else 0
                    df_ins['rata_rata'] = df_raw[found['rata_rata']].astype(str).str.strip() if 'rata_rata' in found else 0

                    if st.button("Mulai Import"):
                        conn = sqlite3.connect(DB_PATH)
                        cur = conn.cursor()
                        existing_df = pd.read_sql_query("SELECT kode_cabang FROM cabang", conn)
                        existing_codes = set(existing_df['kode_cabang'].tolist())
                        inserted = 0
                        created = 0
                        for _, row in df_ins.iterrows():
                            try:
                                if row['kode_cabang'] and row['kode_cabang'] not in existing_codes:
                                    cur.execute("INSERT OR IGNORE INTO cabang (kode_cabang, unit, area) VALUES (?, ?, ?)",
                                                (row['kode_cabang'], row['unit'], row['area']))
                                    existing_codes.add(row['kode_cabang'])
                                    created += 1
                                cur.execute("""
                                  INSERT OR REPLACE INTO pegawai
                                  (nip, nama, kode_cabang, unit, cif_akuisisi, pct_akuisisi, cif_setor,
                                  pct_setor_akuisisi, cif_sudah_transaksi, pct_transaksi_setor,
                                  frek_dari_cif_akuisisi, sv_dari_cif_akuisisi_jt, end_balance, rata_rata,
                                  area, nama_cabang, posisi, avatar_url)
                                  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)
                              """, (
                                  row['nip'], row['nama'], row['kode_cabang'], row['unit'],
                                  row.get('cif_akuisisi'),
                                  row.get('pct_akuisisi'),
                                  row.get('cif_setor'),
                                  row.get('pct_setor_akuisisi'),
                                  row.get('cif_sudah_transaksi'),
                                  row.get('pct_transaksi_setor'),
                                  row.get('frek_dari_cif_akuisisi'),
                                  row.get('sv_dari_cif_akuisisi_jt'),
                                  row.get('end_balance'),
                                  row.get('rata_rata'),
                                  row.get('area',''),
                                  row.get('nama_cabang',''),
                                  row.get('posisi','')
                              ))

                                inserted += 1
                            except Exception as e:
                                st.error(f"Baris {row['nip']} gagal: {e}")
                        conn.commit()
                        conn.close()
                        st.success(f"Import selesai. Insert/Replace: {inserted}. Cabang dibuat: {created}")
                    if st.button("Hapus DB"):
                        conn = sqlite3.connect(DB_PATH)
                        cur = conn.cursor()
                        cur.execute("DELETE FROM pegawai")
                        cur.execute("DELETE FROM cabang")
                        conn.commit()
                        conn.close()
                        st.success("Database dihapus.")

# ---------------------------
# Routing
# ---------------------------
params = st.query_params


if "kode" in params:
    raw = params.get("kode")
    if isinstance(raw, list):
        kode_param = raw[0]
    else:
        kode_param = raw
    st.session_state.view = "pegawai"
    st.session_state.kode = str(kode_param).strip()


# ---------------------------
# View: Cabang
# ---------------------------
# Python / Streamlit part (ganti loop lama dengan ini)
if st.session_state.view == "cabang":
    df = get_cabang_leaderboard()
    total_all = df['total_balance'].sum() if not df.empty else 0
    st.subheader("Top 3 Cabang")
    cols = st.columns(3)

    # medal tuples: (label, emoji, css-class)
    medals = [("Rank 1", "🥇", "gold"), ("Rank 2", "🥈", "silver"), ("Rank 3", "🥉", "bronze")]

    for i, col in enumerate(cols):
        if i < len(df) and i < 3:  # pastikan maksimal 3
            r = df.iloc[i]
            share_pct = (r['total_balance'] / total_all * 100) if total_all > 0 else 0
            label, emoji, cls = medals[i]
            with col:
                st.markdown(f"""
                <div class='leaderboard-card' role='region' aria-label='{label} cabang'>
                  <div style='display:flex;justify-content:space-between;align-items:center'>
                    <div>
                      <div class='medal {cls}'>{emoji} {label}</div>
                      <div style='font-weight:800'>{r['unit']}</div>
                      <div class='small-muted'>{r['area']}</div>
                    </div>
                    <div style='text-align:right'>
                      <div style='font-weight:800'>{format_rp(r['total_balance'])}</div>
                      <div class='small-muted'>{r['kode_cabang']}</div>
                    </div>
                  </div>
                  <div style='margin-top:8px' class='small-muted'>Rata-rata saldo per pegawai: {format_rp(r['rata_rata_saldo'])}</div>
                  <div style='margin-top:8px;display:flex;justify-content:space-between;align-items:center'>
                    <a href='?kode={r["kode_cabang"]}' style='color:var(--accent);font-weight:700;text-decoration:none'>Lihat Pegawai →</a>
                    <div class='small-muted'>{share_pct:0.1f}%</div>
                  </div>
                </div>
                """, unsafe_allow_html=True)

                
    # Python / Streamlit loop: paste menggantikan loop lama
    st.markdown("<br><h4>Daftar Semua Cabang</h4>", unsafe_allow_html=True)

    # helper formatter (pakai format_rp jika sudah ada)
    def fmt(x):
        try:
            return format_rp(x)
        except:
            try:
                v = (x)
                return f"Rp {v:,.0f}".replace(",", ".")
            except:
                return "-"

    # iterate with rank
    for rank, (_, r) in enumerate(df.iterrows(), start=1):
        kode_cb = r.get('kode_cabang', '')
        unit = r.get('unit', '')
        area = r.get('area', '')
        total_balance = r.get('total_balance', 0)
        total_cif = int(r.get('total_cif', 0))
        # choose rank-class for top 3
        rank_cls = ""
        if rank == 1:
            rank_cls = "top1"
            rank_label = "1"
            medal = "🥇"
        elif rank == 2:
            rank_cls = "top2"
            rank_label = "2"
            medal = "🥈"
        elif rank == 3:
            rank_cls = "top3"
            rank_label = "3"
            medal = "🥉"
        else:
            rank_label = str(rank)
            medal = ""


        # build HTML card
        row_html = f"""
        <div class="row-card" role="listitem" aria-label="Cabang {unit}">
            <div class="row-left">
                <div class="rank-badge {rank_cls}" aria-hidden="true">{medal} {rank_label}</div>
                <div class="row-meta">
                    <div class="unit">{unit}</div>
                    <div class="info small-muted">Area: {area} • Kode: {kode_cb}</div>
                    <div class="info small-muted">Total Balance: {fmt(total_balance)} &nbsp;|&nbsp; Total CIF: {total_cif}</div>
                </div>
            </div>
            <div class="row-right">
                <a class="detail-link" href="?kode={kode_cb}">Detail</a>
            </div>
        </div>
        """

        st.markdown(row_html, unsafe_allow_html=True)

# ---------------------------
# Helper: render lineup pitch
# ---------------------------
def render_futsal_responsive(players):
    """
    Responsive futsal layout:
       centered 1
     2   3  (closer to center)
    4       5 (wide)
    Bench below.
    Designed to behave well on narrow mobile viewports.
    """
    import html as _html
    pl = list(players) if players is not None else []

    def esc(x, default='-'):
        try: return _html.escape(str(x))
        except: return default

    def name_of(p): return esc(p.get("nama")) if p else "-"
    def bal_of(p):
        try: return format_rp(p.get("end_balance", 0)) if p else format_rp(0)
        except: return format_rp(0)

    def point_html(idx, p):
        name = name_of(p)
        posisi = esc(p.get("posisi")) if p else "-"
        bal = bal_of(p)
        cif = int(p.get("cif_akuisisi", '0') if p else '0')
        return f'''
        <div class="pt-wrap" title="{name} · {bal} · Posisi: {posisi}">
          <div class="pt-dot">{idx}</div>
          <div class="pt-label">
            <div class="pl-name">{name}</div>
            <div class="pl-posisi">({posisi})</div>
            <div class="pl-bal">{bal} ~ {cif}</div>
          </div>
        </div>
        '''

    # first 5 -> field, rest bench
    field = [pl[i] if i < len(pl) else None for i in range(5)]
    bench = pl[5:] if len(pl) > 5 else []

    template = r'''
    <div style="width:100%;display:flex;justify-content:center;padding:10px 6px;">
      <div style="width:100%;max-width:1100px;">
        <style>
          :root{box-sizing:border-box}
          *{box-sizing:inherit}
          .pitch{
            position:relative;
            background: linear-gradient(180deg,#053a31,#042822);
            border-radius:12px;
            padding:18px;
            border:1px solid rgba(255,255,255,0.03);
            box-shadow: inset 0 30px 60px rgba(0,0,0,0.32);
            overflow:hidden;
            color:rgba(255,255,255,0.95);
          }
          .court-svg{ position:absolute; inset:0; width:100%; height:100%; pointer-events:none; opacity:0.14; }

          /* grid: 5 columns but responsive using minmax() */
          .field-grid{
            display:grid;
            grid-template-columns: minmax(10px,1fr) repeat(3, minmax(80px, 160px)) minmax(10px,1fr);
            grid-template-rows: min-content 1fr 1fr;
            gap:8px 14px;
            align-items:end;
            justify-items:center;
            min-height:360px;
            width:100%;
          }
          /* positions mapping */
          .p1{ grid-column: 2 / 5; grid-row: 1 / 2; }   /* centered, spans two inner cols */
          .p2{ grid-column: 2 / 3; grid-row: 2 / 3; }   /* left-center */
          .p3{ grid-column: 4 / 5; grid-row: 2 / 3; }   /* right-center */
          .p4{ grid-column: 1 / 2; grid-row: 3 / 4; }   /* far-left */
          .p5{ grid-column: 5 / 6; grid-row: 3 / 4; }   /* far-right */

          .pt-wrap{ display:flex; flex-direction:column; align-items:center; gap:6px; width:100%; max-width:220px; padding:6px; }
          .pt-dot{
            width:56px; height:56px; border-radius:50%;
            background: radial-gradient(circle at 30% 30%, #26c57a, #00a060);
            display:flex; align-items:center; justify-content:center;
            font-weight:900; color:#052c20; box-shadow:0 8px 18px rgba(0,0,0,0.45);
            border:2px solid rgba(255,255,255,0.06); font-size:18px;
          }
          .pt-label{ text-align:center; }
          .pl-name{ font-weight:800; font-size:13px; line-height:1.05; }
          .pl-posisi{ font-size:8px; margin-top:2px; color:rgba(255,255,255,0.8); }
          .pl-bal{ font-weight:700; font-size:13px; margin-top:4px; color:rgba(255,255,255,0.9); }

          .separator{ margin-top:18px; border-top:2px dashed rgba(255,255,255,0.05); padding-top:12px; }
          .bench{ display:flex; flex-wrap:wrap; gap:12px; justify-content:center; align-items:flex-start; }

          /* SMALL SCREENS: scale things down and allow wrapping */
          @media (max-width:720px){
            .field-grid{
              grid-template-columns: minmax(6px,1fr) repeat(3, minmax(60px, 120px)) minmax(6px,1fr);
              grid-template-rows: min-content min-content min-content;
              gap:10px 8px;
              min-height:320px;
            }
            .pt-wrap{ max-width:140px; padding:4px; }
            .pt-dot{ width:48px; height:48px; font-size:15px; }
            .pl-name{ font-size:12px }
            .pl-bal{ font-size:12px }
          }

          /* VERY NARROW: stack bench, allow players to reflow */
          @media (max-width:420px){
            .field-grid{
              grid-template-columns: 1fr 1fr;
              grid-template-rows: auto auto auto;
              gap:10px;
            }
            .p1{ grid-column: 1 / 3; grid-row: 1 / 2; }
            .p2{ grid-column: 1 / 2; grid-row: 2 / 3; }
            .p3{ grid-column: 2 / 3; grid-row: 2 / 3; }
            .p4{ grid-column: 1 / 2; grid-row: 3 / 4; }
            .p5{ grid-column: 2 / 3; grid-row: 3 / 4; }
            .pt-wrap{ max-width:120px; }
            .pt-dot{ width:44px; height:44px; font-size:14px; }
          }
        </style>

        <div class="pitch">
          <!-- svg of court (decorative, won't affect layout) -->
          <svg class="court-svg" viewBox="0 0 1000 600" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="40" y="30" width="920" height="540" rx="18" ry="18" fill="none" stroke="white" stroke-width="4" opacity="0.08"/>
            <line x1="500" y1="30" x2="500" y2="570" stroke="white" stroke-width="2" opacity="0.06" />
            <circle cx="500" cy="300" r="60" fill="none" stroke="white" stroke-width="2" opacity="0.06" />
          </svg>

          <div class="field-grid" role="region" aria-label="Futsal lineup">
            <div class="p1">__P1__</div>
            <div class="p2">__P2__</div>
            <div class="p3">__P3__</div>
            <div class="p4">__P4__</div>
            <div class="p5">__P5__</div>
          </div>

          <div class="separator">
            <div class="bench">__BENCH__</div>
          </div>

        </div>
      </div>
    </div>
    '''

    pieces = {
        '__P1__': point_html(1, field[0]),
        '__P2__': point_html(2, field[1]),
        '__P3__': point_html(3, field[2]),
        '__P4__': point_html(4, field[3]),
        '__P5__': point_html(5, field[4])
    }

    bench_html = ""
    idx = 6
    if bench:
        for b in bench:
            bench_html += point_html(idx, b)
            idx += 1
    else:
        bench_html = '<div style="color:rgba(255,255,255,0.6);font-size:13px;padding:8px">Bench kosong</div>'

    html_out = template
    for k, v in pieces.items():
        html_out = html_out.replace(k, v)
    html_out = html_out.replace('__BENCH__', bench_html)

    return html_out

# ---------------------------
# View: Pegawai (Leaderboard as lineup)
# ---------------------------
if st.session_state.view == "pegawai":
    kode = st.session_state.kode or "ALL"
    st.subheader(f"Leaderboard Pegawai")
    dfc = get_cabang_leaderboard()
    options = ["ALL"] + dfc.apply(lambda r: f"{r['kode_cabang']} — {r['unit']}", axis=1).tolist()

    # tentukan default index
    if st.session_state.kode == "ALL":
        default_index = 0
    else:
        # coba cari dalam list cabang
        cabang_label = None
        for r in dfc.itertuples():
            if st.session_state.kode == r.kode_cabang:
                cabang_label = f"{r.kode_cabang} — {r.unit}"
                break
        if cabang_label and cabang_label in options:
            default_index = options.index(cabang_label)
        else:
            default_index = 0

    chosen = st.selectbox("Ketik Disini", options=options, index=default_index)

    if chosen == "ALL":
        st.session_state.kode = "ALL"
    elif " — " in chosen:
        st.session_state.kode = chosen.split(" — ",1)[0].strip()
    else:
        st.session_state.kode = chosen

    # reload pegawai
    dfp_all = get_pegawai(st.session_state.kode)
    total_pegawai = len(dfp_all)
    total_balance = dfp_all["end_balance"].sum()
    avg_balance = dfp_all["end_balance"].mean()
    nihil = (pd.to_numeric(dfp_all["cif_akuisisi"], errors="coerce").fillna(0) == 0).sum()
    total_cif = pd.to_numeric(dfp_all["cif_akuisisi"], errors="coerce").fillna(0).sum()

    # top performer
    top_row = dfp_all.iloc[0]
    top_name = top_row["nama"]
    top_value = top_row["end_balance"]

    # share top 3
    top3_share = dfp_all["end_balance"].head(3).sum() / total_balance * 100

    if dfp_all.empty:
        st.warning("Tidak ada pegawai untuk filter ini.")
    else:
        # pagination
        total_items = len(dfp_all)
        page_size = total_items
        st.session_state.page_size = page_size
        total_pages = max(1, math.ceil(total_items / page_size))
        if st.session_state.page_num > total_pages:
            st.session_state.page_num = total_pages
        # slice
        start = (st.session_state.page_num - 1) * page_size
        end = start + page_size
        dfp_page = dfp_all.iloc[start:end]

        # ambil daftar pegawai terurut dari DataFrame (urut menurut end_balance DESC)
        dfp_all = get_pegawai(st.session_state.kode)
        players = dfp_all.to_dict('records')  # urutan ranking
        st.markdown(f"""
        <div class="stat-container">
            <div class="stat-card">
                <div class="stat-title">Total Pegawai</div>
                <div class="stat-value">{total_pegawai}</div>
                <div class="stat-extra">Orang</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Mtd Nov</div>
                <div class="stat-value">{format_rp(total_balance)}</div>
                <div class="stat-extra">Akumulasi saldo seluruh pegawai</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Rata-rata Saldo</div>
                <div class="stat-value">{format_rp(avg_balance)}</div>
                <div class="stat-extra">Per pegawai</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Top Performer</div>
                <div class="stat-value">{top_name}</div>
                <div class="stat-extra">{format_rp(top_value)}</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Total CIF</div>
                <div class="stat-value">{int(total_cif)}</div>
                <div class="stat-extra">Jumlah CIF seluruh pegawai</div>
            </div>
            <div class="stat-card">
                <div class="stat-title">Pegawai Nihil Akuisisi</div>
                <div class="stat-value">{nihil}</div>
                <div class="stat-extra">Orang</div>
            </div>
        </div>
        """, unsafe_allow_html=True)

        html = render_futsal_responsive(players)
        import streamlit.components.v1 as components
        components.html(html, height=720, scrolling=True)

        st.markdown("---")
        # list view of page
        st.markdown("<h4>Daftar Pegawai</h4>", unsafe_allow_html=True)
        #urut berdasrkan end balance tapi takutnya ini berupa string
        dfp_sorted = dfp_page.sort_values(['end_balance'], ascending=[False]).reset_index(drop=True)

        for idx, (_, r) in enumerate(dfp_sorted.iterrows(), start=1):

            kode_cb = r.get('kode_cabang', '')
            nama = r.get('nama', '-')
            nip = r.get('nip', '')
            cif = int(r.get('cif_akuisisi', ''))
            cif_display = (cif) if cif not in ['', None] else '-'
            end_balance = format_rp(r.get('end_balance', 0))

            # rank
            if idx == 1:
                rank_cls = "top1"; medal = "🥇"; rank_label = "1"
            elif idx == 2:
                rank_cls = "top2"; medal = "🥈"; rank_label = "2"
            elif idx == 3:
                rank_cls = "top3"; medal = "🥉"; rank_label = "3"
            else:
                rank_cls = ""; medal = ""; rank_label = str(idx)

            # HTML TANPA INDENTASI (sangat penting!)
            row_html = f"""
              <div class="row-card">
                <div class="row-left">
                  <div class="rank-badge {rank_cls}">{medal} {rank_label}</div>
                  <div class="row-meta">
                    <div class="name">{nama}</div>
                    <div class="small-muted">{nip} · {r.get('posisi','')} · {cif_display}</div>
                  </div>
                </div>
                <div class="row-right">{end_balance}</div>
              </div>
              """

            st.markdown(row_html, unsafe_allow_html=True)


        # pagination controls
        b1,b2,b3 = st.columns([1,1,6])
        with b1:
            if st.button("← Prev", key="pg_prev") and st.session_state.page_num > 1:
                st.session_state.page_num -= 1
                st.rerun()
        with b2:
            if st.button("Next →", key="pg_next") and st.session_state.page_num < total_pages:
                st.session_state.page_num += 1
                st.rerun()
        with b3:
            st.markdown(f"<div class='small-muted'>Halaman {st.session_state.page_num} / {total_pages} — Total pegawai: {total_items}</div>", unsafe_allow_html=True)

    if st.button("← Kembali ke Cabang"):
        st.session_state.view = "cabang"
        st.session_state.kode = None
        st.session_state.page_num = 1
        st.query_params.clear()
        st.rerun()


