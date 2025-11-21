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
        area TEXT
    )
    """)
    cur.execute("""
    CREATE TABLE IF NOT EXISTS pegawai (
        nip TEXT PRIMARY KEY,
        nama TEXT,
        kode_cabang TEXT,
        unit TEXT,
        cif_akuisisi TEXT,
        end_balance REAL,
        area TEXT,
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
               IFNULL(AVG(p.end_balance),0) AS rata_rata_saldo
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
            SELECT nip, nama, kode_cabang, unit, cif_akuisisi, IFNULL(end_balance,0) AS end_balance, area, avatar_url
            FROM pegawai
            ORDER BY end_balance DESC
        """, conn)
    else:
        # check existing kode_cabang
        df_cabang = pd.read_sql_query("SELECT kode_cabang FROM cabang", conn)
        kode_list = df_cabang['kode_cabang'].tolist()
        if kode in kode_list:
            df = pd.read_sql_query("""
                SELECT nip, nama, kode_cabang, unit, cif_akuisisi, IFNULL(end_balance,0) AS end_balance, area, avatar_url
                FROM pegawai
                WHERE kode_cabang = ?
                ORDER BY end_balance DESC
            """, conn, params=(kode,))
        else:
            q = """
            SELECT nip, nama, kode_cabang, unit, cif_akuisisi, IFNULL(end_balance,0) AS end_balance, area, avatar_url
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
        v = int(round(float(value)))
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
        val = float(s)
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
.header-logo {{
  display:flex;align-items:center;gap:12px;
}}
.logo-img {{ width:64px;height:64px;border-radius:12px;object-fit:cover;border:2px solid rgba(255,255,255,0.06);box-shadow:0 8px 24px rgba(0,0,0,0.6); }}
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
col1, col3 = st.columns([3,3])
with col1:
    st.markdown(f"""
    <div class='header-logo'>
      <img src='{LOGO_PATH}' class='logo-img'/>
      <div>
        <div style='font-weight:800;font-size:36px'>GMM RACEBOARD</div>
        <div class='small-muted'>Dashboard Leaderboard R11</div>
      </div>
    </div>
    """, unsafe_allow_html=True)

with col3:
    if st.button("Leaderboard Cabang"):
        st.session_state.view = "cabang"
        st.session_state.kode = None
        st.rerun()
    if st.button("Leaderboard Pegawai"):
        st.session_state.view = "pegawai"
        st.session_state.kode = "ALL"
        st.rerun()
    if st.button("Update Data"):
        st.session_state.show_update_panel = not st.session_state.show_update_panel
        st.rerun()

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
                    'cif_akuisisi': ['#cif akuisisi','cif_akuisisi','cif'],
                    'end_balance': ['end_balance','Rata - Rata Saldo Tabungan (jt)'],
                    'area': ['area','wilayah','region']
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
                    df_ins['cif_akuisisi'] = df_raw[found['cif_akuisisi']].astype(str).str.strip() if 'cif_akuisisi' in found else ''
                    df_ins['end_balance'] = df_raw[found['end_balance']]
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
                                    (nip, nama, kode_cabang, unit, cif_akuisisi, end_balance, area, avatar_url)
                                    VALUES (?, ?, ?, ?, ?, ?, ?, NULL)
                                """, (row['nip'], row['nama'], row['kode_cabang'], row['unit'], row['cif_akuisisi'], float(row['end_balance']), row['area']))
                                inserted += 1
                            except Exception as e:
                                st.error(f"Baris {row['nip']} gagal: {e}")
                        conn.commit()
                        conn.close()
                        st.success(f"Import selesai. Insert/Replace: {inserted}. Cabang dibuat: {created}")

# ---------------------------
# Routing
# ---------------------------
params = st.query_params
if 'kode' in params:
    kode_param = params.get('kode')[0] if isinstance(params.get('kode'), list) else params.get('kode')
    st.session_state.view = 'pegawai'
    st.session_state.kode = kode_param

# ---------------------------
# View: Cabang
# ---------------------------
if st.session_state.view == "cabang":
    df = get_cabang_leaderboard()
    total_all = df['total_balance'].sum() if not df.empty else 0
    st.subheader("Top 3 Cabang")
    cols = st.columns(3)
    for i, col in enumerate(cols):
        if i < len(df):
            r = df.iloc[i]
            share_pct = (r['total_balance'] / total_all * 100) if total_all > 0 else 0
            with col:
                st.markdown(f"""
                <div class='leaderboard-card' role='region' aria-label='Top {i+1} cabang'>
                  <div style='display:flex;justify-content:space-between;align-items:center'>
                    <div>
                      <div class='small-muted'>TOP-{i+1}</div>
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
    st.markdown("<br><h4>Daftar Semua Cabang</h4>", unsafe_allow_html=True)
    for _, r in df.iterrows():
        c1, c2, c3 = st.columns([1,6,2])
        with c1:
            st.markdown(f"<div style='font-weight:700'>{r['area']}</div>", unsafe_allow_html=True)
        with c2:
            st.markdown(f"<div style='font-weight:700'>{r['unit']}</div><div class='small-muted'>{r['kode_cabang']}</div>", unsafe_allow_html=True)
        with c3:
            if st.button("Detail", key=f"detail_{r['kode_cabang']}"):
                st.session_state.view = "pegawai"
                st.session_state.kode = r['kode_cabang']
                st.query_params.update(kode=r['kode_cabang'])
                st.session_state.page_num = 1
                st.rerun()

    # charts
    st.markdown("<hr style='border-color:rgba(255,255,255,0.04)'>", unsafe_allow_html=True)
    df_area = df.groupby('area', dropna=False).agg({'total_balance':'sum'}).reset_index()
    df_area = df_area.sort_values('total_balance', ascending=False)
    if df_area['total_balance'].sum() > 0:
        bar = alt.Chart(df_area).mark_bar().encode(x='total_balance:Q', y=alt.Y('area:N', sort='-x'), tooltip=['area','total_balance'])
        st.altair_chart(bar, use_container_width=True)
    else:
        st.info("Belum ada data balance")

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
        bal = bal_of(p)
        return f'''
        <div class="pt-wrap" title="{name} · {bal}">
          <div class="pt-dot">{idx}</div>
          <div class="pt-label">
            <div class="pl-name">{name}</div>
            <div class="pl-bal">{bal}</div>
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
    st.subheader(f"Leaderboard Pegawai — {kode}")
    dfc = get_cabang_leaderboard()
    options = ["ALL"] + dfc['area'].dropna().unique().tolist() + dfc.apply(lambda r: f"{r['kode_cabang']} — {r['unit']}", axis=1).tolist()
    chosen = st.selectbox("Filter: ALL / Area / Cabang", options=options, index=0)
    if chosen == "ALL":
        st.session_state.kode = "ALL"
    elif " — " in chosen:
        st.session_state.kode = chosen.split(" — ",1)[0].strip()
    else:
        st.session_state.kode = chosen

    # reload pegawai
    dfp_all = get_pegawai(st.session_state.kode)
    if dfp_all.empty:
        st.warning("Tidak ada pegawai untuk filter ini.")
    else:
        # pagination
        total_items = len(dfp_all)
        page_size = st.selectbox("Items per page", [5,10,20,50], index=1)
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

        html = render_futsal_responsive(players)
        import streamlit.components.v1 as components
        components.html(html, height=720, scrolling=True)






        st.markdown("---")
        # list view of page
        st.markdown("<h4>Daftar Pegawai</h4>", unsafe_allow_html=True)
        for _, r in dfp_page.iterrows():
            c1,c2,c3 = st.columns([1,6,2])
            with c1:
                kode_cb = r.get('kode_cabang') or ''
                st.markdown(f"<div style='font-weight:700'>{kode_cb}</div>", unsafe_allow_html=True)
            with c2:
                cif = r.get('cif_akuisisi') or ''
                try:
                    cif_display = str(int(float(cif))) if cif != '' and str(cif).replace('.','',1).isdigit() else cif
                except:
                    cif_display = cif
                st.markdown(f"<div style='font-weight:700'>{r['nama']}</div><div class='small-muted'>{r['nip']} · CIF: {cif_display}</div>", unsafe_allow_html=True)
            with c3:
                st.markdown(f"<div style='font-weight:800'>{format_rp(r['end_balance'])}</div>", unsafe_allow_html=True)

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
