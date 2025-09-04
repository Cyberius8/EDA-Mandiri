# app_optimized.py
# Streamlit dashboard: Persebaran Lokasi Bank — versi rapi (DB-only)
# - Hanya baca data dari bank_dashboard.db
# - Hapus seluruh fitur upload/update data
# - Route terbatas: dashboard | map | detailb | detaile
# - Docstring pada setiap fungsi dan helper
# - UI gelap + kartu, popup peta interaktif, rute OSRM

import os
import re
import sqlite3
import math
from datetime import datetime
from math import atan2, radians, degrees, sin, cos
from urllib.parse import quote_plus, urlparse

import numpy as np
import pandas as pd
import plotly.express as px
import plotly.graph_objects as go
import requests
import streamlit as st
import folium
from streamlit_folium import st_folium
from zoneinfo import ZoneInfo  # Python 3.9+

# =========================================
# PAGE SETUP & GLOBAL STYLE
# =========================================
st.set_page_config(page_title="🏦 Dashboard Jaringan Cabang & SDM", layout="wide")
st.session_state.setdefault("start_unit", None)
st.session_state.setdefault("end_unit", None)
st.session_state.setdefault("selected_unit", None)

st.title("🏦 Dashboard Jaringan Cabang & SDM")

# ===== Global CSS (tema gelap + kartu) =====
st.markdown(
    """
<style>
:root {
  --bg: #0b1220;
  --panel: #0d1326;
  --muted: #94a3b8;
  --border: rgba(148,163,184,.15);
  --shadow: rgba(2,6,23,.25);
}
html, body, [data-testid="stAppViewContainer"] { background: var(--bg); }
.block-container { padding-top: 1.2rem; }

.card {
  background: var(--panel);
  border: 1px solid var(--border);
  border-radius: 16px;
  padding: 14px 16px;
  box-shadow: 0 6px 18px var(--shadow);
}

.kpi {
  border-radius: 16px; padding:16px; color:white;
  background: linear-gradient(135deg, #0ea5e9 0%, #1d4ed8 45%, #0f172a 100%);
  box-shadow: 0 8px 24px rgba(2,6,23,.35);
}
.kpi .title {font-size:0.95rem; font-weight:700; opacity:.9; margin:0}
.kpi .val   {font-size:1.9rem; font-weight:900; margin-top:4px; line-height:1.2}
.kpi .sub   {font-size:.9rem; opacity:.85; margin-top:6px}

.kpi.green { background: linear-gradient(135deg, #22c55e 0%, #16a34a 40%, #064e3b 100%); }
.kpi.purple { background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 40%, #1e1b4b 100%); }

hr {border: none; height:1px; background: linear-gradient(90deg, transparent, rgba(148,163,184,.35), transparent);} 

.emp-avatar {
  width:120px; height:120px; object-fit:cover;
  border-radius:9999px; border:1px solid rgba(148,163,184,.25);
  box-shadow: 0 6px 16px rgba(2,6,23,.15);
}

/* IG-style profile (dipakai di halaman Detail Employee) */
.ig-card{background:var(--panel,#0d1326);border:1px solid rgba(148,163,184,.15);border-radius:20px;box-shadow:0 10px 30px rgba(2,6,23,.25)}
.ig-header{display:flex;gap:28px;align-items:center;padding:18px}
.ig-avatar{width:110px;height:110px;border-radius:999px;object-fit:cover;border:2px solid rgba(148,163,184,.25)}
.ig-username{font-size:1.35rem;font-weight:700;letter-spacing:.2px}
.ig-actions{display:flex;gap:8px;flex-wrap:wrap}
.ig-btn{padding:6px 12px;border:1px solid rgba(148,163,184,.2);background:rgba(148,163,184,.08);border-radius:10px;font-size:.9rem}
.ig-bio{padding:0 18px 12px}
.ig-name{font-weight:700;margin-bottom:2px}
.ig-micro{color:#94a3b8;font-size:.9rem}
.ig-stats{display:flex;gap:22px;padding:10px 18px;border-top:1px solid rgba(148,163,184,.12);border-bottom:1px solid rgba(148,163,184,.12)}
.ig-stat .n{font-weight:700}
.ig-highlights{display:flex;gap:12px;padding:12px 18px}
.ig-chip{display:flex;flex-direction:column;align-items:center;gap:6px}
.ig-chip .ring{width:64px;height:64px;border-radius:999px;border:2px solid rgba(148,163,184,.25);display:grid;place-items:center;font-size:1.1rem}
.ig-chip .cap{font-size:.85rem;color:#cbd5e1;max-width:76px;text-align:center}
.ig-tabs{display:flex;gap:24px;justify-content:center;padding:6px 0;border-top:1px solid rgba(148,163,184,.12)}
.ig-tab{padding:10px 0;font-weight:600;color:#94a3b8}
.ig-tab.active{color:#e5e7eb;border-bottom:2px solid #e5e7eb}
.ig-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;padding:10px}
.tile{position:relative;aspect-ratio:1/1;background:rgba(148,163,184,.08);border:1px solid rgba(148,163,184,.12);border-radius:8px;display:grid;place-items:center;text-align:center;padding:10px}
.tile .t{font-size:.8rem;color:#94a3b8;margin-bottom:4px}
.tile .v{font-size:.95rem;font-weight:700}
.copy{display:inline-flex;gap:6px;align-items:center;padding:4px 8px;border:1px solid rgba(148,163,184,.2);border-radius:10px;background:rgba(148,163,184,.06);font-size:.85rem}

@media (max-width: 980px){
  .ig-header{gap:16px}
  .ig-grid{grid-template-columns:repeat(2,1fr)}
}
</style>
""",
    unsafe_allow_html=True,
)

# =========================================
# CONSTANTS & GLOBALS
# =========================================
DB_PATH = "bank_dashboard.db"
PLACEHOLDERS = {"-", "–", "—", "N/A", "NA", "n/a", "na", "", "None", "null", "Null"}
ALLOWED_ROUTES = {"dashboard", "detail", "detailb", "detaile", "map"}
OSRM_BASE = "https://router.project-osrm.org/route/v1/driving"

# =========================================
# EMPLOYEE SCHEMA (FIXED KEYS -> FLEXIBLE COLUMNS)
# =========================================
EMP_COLS = {
    "NO": "No.",
    "NIP": "NIP",
    "NAMA": "Nama",
    "GENDER": "Gender",
    "POSISI": "Posisi",
    "UNIT": "Unit Kerja",
    "DEP": "Dep",
    "AREA": "Area",
    "STATUS_JABATAN": "Status Jabatan",
    "STATUS_PEGAWAI": "Status Pegawai",
    "BIRTHDATE": "Birthdate",
    "SOURCE": "Source Pegawai",
}

def has_emp(df: pd.DataFrame, col_key: str) -> bool:
    """Cek apakah kolom standar (EMP_COLS[col_key]) ada di DataFrame pegawai."""
    return EMP_COLS[col_key] in df.columns

def emp(df: pd.DataFrame, col_key: str) -> str | None:
    """Ambil nama kolom mentah dari EMP_COLS jika tersedia di df; jika tidak, kembalikan None."""
    return EMP_COLS[col_key] if has_emp(df, col_key) else None

def _row_area_value(row: pd.Series) -> str:
    """Ambil nilai Area/Wilayah dari baris branch dengan berbagai kemungkinan nama kolom."""
    for c in ["AREA", "Area", "Wilayah", "Regional", "Kanwil", "Area/Kanwil"]:
        if c in row.index:
            v = row.get(c)
            if pd.notna(v) and str(v).strip():
                return str(v).strip()
    return ""

def _area_to_color(area_name: str) -> str:
    """Mapping nama area -> warna folium.Icon (sekadar pembeda visual)."""
    a = (area_name or "").strip().casefold()
    if "denpasar" in a:
        return "purple"
    if "kuta" in a:
        return "blue"
    if "mataram" in a:
        return "orange"   # folium tak punya 'yellow'
    if "kupang" in a:
        return "green"
    return "cadetblue"

def _safe_get(row: pd.Series, col: str | None):
    """Ambil row[col] jika col ada, selain itu None."""
    return row.get(col, None) if col else None

def _calc_age(dob_val) -> int | None:
    """Hitung usia (tahun) dengan akurat. Return None bila DOB tidak valid."""
    if dob_val is None or pd.isna(dob_val):
        return None
    dob = pd.to_datetime(dob_val, errors="coerce")
    if pd.isna(dob):
        return None
    today = datetime.now(ZoneInfo("Asia/Makassar")).date()
    dob = dob.date()
    return today.year - dob.year - ((today.month, today.day) < (dob.month, dob.day))

def _looks_like_url(s: str) -> bool:
    """Validasi cepat string URL."""
    try:
        u = urlparse(s)
        return u.scheme in {"http", "https"} and bool(u.netloc)
    except Exception:
        return False

# =========================================
# QUERY PARAMS & ROUTING
# =========================================
def _flatten_qp(qp_raw: dict) -> dict:
    """Streamlit bisa mengembalikan list untuk tiap param. Rata menjadi dict kunci->nilai tunggal."""
    flat = {}
    for k, v in qp_raw.items():
        flat[k] = v[0] if isinstance(v, list) and v else v
    return flat

def read_qp() -> dict:
    """Baca query params secara kompatibel (Streamlit modern & lama)."""
    try:
        qp = dict(st.query_params)  # Streamlit 1.33+
    except Exception:
        qp = st.experimental_get_query_params()
    return _flatten_qp(qp)

def get_route() -> tuple[str, dict]:
    """Ambil route dari query params + qp mentahnya."""
    qp = read_qp()
    route = (qp.get("route") or "dashboard").lower()
    if route not in ALLOWED_ROUTES:
        route = "dashboard"
    return route, qp

def goto(route: str, **params):
    """Ubah query params untuk navigasi antar-halaman."""
    assert route in ALLOWED_ROUTES
    qp = {"route": route}
    qp.update({k: v for k, v in params.items() if v not in (None, "")})
    try:
        st.query_params.from_dict(qp)
    except Exception:
        st.experimental_set_query_params(**qp)
    st.rerun()

# ------- ROUTER STATE -------
route, qp = get_route()
if qp.get("unit"):
    st.session_state["selected_unit"] = qp["unit"]

# ------- HEADERBAR (tanpa 'Update Data') -------
with st.container():
    c1, c2, c3, c4 = st.columns([1, 1, 1, 1])

    # fallback unit: selected_unit -> start_unit -> end_unit
    nav_unit = (
        st.session_state.get("selected_unit")
        or st.session_state.get("start_unit")
        or st.session_state.get("end_unit")
    )

    if c1.button("Dashboard", use_container_width=True):
        goto("dashboard")
    if c2.button("Distribution Branch", use_container_width=True):
        goto("map")
    if c3.button("Detail Branch", use_container_width=True):
        goto("detailb", unit=nav_unit)
    if c4.button("Detail Employee", use_container_width=True):
        goto("detaile", unit=nav_unit)

# =========================================
# HELPERS (formatting & link)
# =========================================
def fmt_dur(sec: int) -> str:
    """Format durasi detik → 'H j M m' atau 'M m'."""
    m = int(round(sec / 60))
    h, m = divmod(m, 60)
    return f"{h} j {m} m" if h else f"{m} m"

def haversine_km(a, b) -> float:
    """Hitung jarak great-circle (km) antar 2 titik (lat,lon)."""
    lat1, lon1 = map(math.radians, a)
    lat2, lon2 = map(math.radians, b)
    dlat, dlon = lat2 - lat1, lon2 - lon1
    h = math.sin(dlat / 2) ** 2 + math.cos(lat1) * math.cos(lat2) * math.sin(dlon / 2) ** 2
    return 6371.0088 * (2 * math.asin(math.sqrt(h)))

def osrm_drive(a_latlon, b_latlon, avoid_ferries: bool = False):
    """Ambil ringkasan rute OSRM (tanpa steps)."""
    a_lat, a_lon = a_latlon; b_lat, b_lon = b_latlon
    url = f"{OSRM_BASE}/{a_lon},{a_lat};{b_lon},{b_lat}?overview=full&geometries=geojson&steps=false"
    if avoid_ferries:
        url += "&exclude=ferry"
    r = requests.get(url, timeout=30).json()
    route = r["routes"][0]
    return {
        "distance_m": route["distance"],
        "duration_s": route["duration"],
        "coords": [(lat, lon) for lon, lat in route["geometry"]["coordinates"]],
    }

def nip_to_photo_id(nip_val: object) -> str | None:
    """Ambil 6 digit terakhir lalu buang 1 digit terakhir → 5 digit (contoh: 2096734637 -> 73463)."""
    s = re.sub(r"\D", "", str(nip_val or ""))
    return s[-6:-1] if s else None

def photo_url_from_row(row: pd.Series, id_col: str | None, photo_col: str | None) -> str | None:
    """Ambil URL foto: prioritas kolom URL eksplisit, fallback ke URL berbasis NIP."""
    if photo_col:
        url = str(row.get(photo_col, "") or "").strip()
        if url and _looks_like_url(url):
            return url
    pid = nip_to_photo_id(row.get(id_col)) if id_col else None
    return f"https://www.mandiritams.com/mandiri_media/photo/{pid}.jpg" if pid else None

def norm_txt(x):
    """Normalisasi teks untuk join (strip + casefold)."""
    if pd.isna(x):
        return None
    return str(x).strip().casefold()

def extract_unit_from_popup(popup_html: str):
    """Ambil atribut data-unit dari HTML popup (kalau diperlukan)."""
    m = re.search(r'data-unit="([^"]+)"', str(popup_html))
    return m.group(1).strip() if m else None

def pick_col(df: pd.DataFrame, candidates):
    """Ambil kolom pertama yang cocok dari daftar kandidat."""
    for c in candidates:
        if c in df.columns:
            return c
    return None

def pick_branch_unit_col(df_branch: pd.DataFrame):
    """Heuristik nama kolom unit di tabel cabang."""
    for c in ["Unit Kerja", "Kantor", "Nama Cabang", "Nama Unit"]:
        if c in df_branch.columns:
            return c
    return None

def _base_prefix() -> str:
    """Ambil base URL app dari secrets/env; fallback '/'."""
    base = None
    try:
        base = st.secrets["APP_BASE_URL"]
    except Exception:
        base = os.environ.get("APP_BASE_URL") or os.environ.get("BASE_PREFIX") or "/"
    return str(base).rstrip("/")

def link_detail(unit_name: str) -> str:
    """Bangun link ke halaman detail branch untuk unit tertentu."""
    return f'{_base_prefix()}/?route=detailb&unit={quote_plus(unit_name or "")}'

def link_employee_detail_row(row: pd.Series, id_col: str | None, name_col: str | None) -> str:
    """Bangun link ke halaman detail employee (pakai NIP lebih dulu, fallback nama)."""
    base = _base_prefix()
    emp_id = row.get(id_col, None) if id_col else None
    name  = row.get(name_col, None) if name_col else None
    if pd.notna(emp_id) and str(emp_id).strip():
        return f"{base}/?route=detaile&nip={quote_plus(str(emp_id))}"
    if pd.notna(name) and str(name).strip():
        return f"{base}/?route=detaile&nama={quote_plus(str(name))}"
    return ""

# =========================================
# DB I/O + PREP (READ-ONLY)
# =========================================
@st.cache_data(show_spinner=False)
def _db_mtime(path: str) -> float:
    """Ambil mtime file DB untuk invalidasi cache ketika DB berubah."""
    return os.path.getmtime(path) if os.path.exists(path) else 0.0

@st.cache_data(show_spinner=False)
def read_table_cached(table_name: str, mtime: float) -> pd.DataFrame:
    """Baca tabel dari SQLite (cached by mtime)."""
    if not os.path.exists(DB_PATH):
        return pd.DataFrame()
    with sqlite3.connect(DB_PATH) as conn:
        try:
            return pd.read_sql(f"SELECT * FROM {table_name}", conn)
        except Exception:
            return pd.DataFrame()

@st.cache_data(show_spinner=False)
def ensure_parsed_latlon(df_branch: pd.DataFrame) -> pd.DataFrame:
    """Pastikan tabel cabang punya 'Latitude' & 'Longitude' (parse dari 'Latitude_Longitude' bila perlu)."""
    df = df_branch.copy()
    if "Latitude" in df.columns and "Longitude" in df.columns:
        return df
    if "Latitude_Longitude" in df.columns:
        latlon = df["Latitude_Longitude"].astype(str).str.strip()
        parts = latlon.str.split(",", n=1, expand=True)
        if parts.shape[1] == 2:
            df["Latitude"]  = pd.to_numeric(parts[0].str.strip(), errors="coerce")
            df["Longitude"] = pd.to_numeric(parts[1].str.strip(), errors="coerce")
            df = df.dropna(subset=["Latitude","Longitude"]).reset_index(drop=True)
    return df

@st.cache_data(show_spinner=False)
def attach_unit_norm(df_b: pd.DataFrame, df_e: pd.DataFrame):
    """Tambahkan kolom '_unit_norm' (normalisasi nama unit) pada cabang & pegawai untuk join cepat."""
    bcol = pick_branch_unit_col(df_b)
    if bcol:
        df_b = df_b.copy()
        df_b["_unit_norm"] = df_b[bcol].apply(norm_txt)
    else:
        df_b = df_b.copy()
        df_b["_unit_norm"] = None

    df_e = df_e.copy()
    if "Unit Kerja" in df_e.columns:
        df_e["_unit_norm"] = df_e["Unit Kerja"].apply(norm_txt)
    else:
        emp_possible = [c for c in ["Kantor", "Nama Cabang", "Unit"] if c in df_e.columns]
        if emp_possible:
            df_e["_unit_norm"] = df_e[emp_possible[0]].apply(norm_txt)
        else:
            df_e["_unit_norm"] = None
    return df_b, df_e, bcol

@st.cache_data(show_spinner=False)
def load_data(db_path: str) -> tuple[pd.DataFrame, pd.DataFrame, str, pd.DataFrame]:
    """Baca tabel 'branches' & 'employees' dari DB + persiapan kolom bantu untuk dashboard."""
    mtime = _db_mtime(db_path)
    df_branch = read_table_cached("branches", mtime)
    df_employee = read_table_cached("employees", mtime)

    if not df_branch.empty:
        df_branch = ensure_parsed_latlon(df_branch)

    if not (df_branch.empty and df_employee.empty):
        df_branch, df_employee, BRANCH_UNIT_COL = attach_unit_norm(df_branch, df_employee)
    else:
        BRANCH_UNIT_COL = None

    unit_map = pd.DataFrame()
    if BRANCH_UNIT_COL and BRANCH_UNIT_COL in df_branch.columns:
        unit_map = df_branch[[BRANCH_UNIT_COL]].copy()
        unit_map["_unit_norm"] = unit_map[BRANCH_UNIT_COL].apply(norm_txt)
        unit_map = unit_map.drop_duplicates("_unit_norm")

    return df_branch, df_employee, BRANCH_UNIT_COL, unit_map

# =========================================
# PLOTLY HELPERS (chart siap pakai)
# =========================================
def bar_grad(series, title="", orientation="v"):
    """Bar chart (horizontal/vertikal) dengan gradasi warna biru."""
    data = series.reset_index()
    data.columns = ["Kategori", "Jumlah"]
    if orientation == "v":
        fig = px.bar(data, x="Kategori", y="Jumlah", color="Jumlah", color_continuous_scale="Blues", text="Jumlah")
        fig.update_traces(textposition="outside")
    else:
        fig = px.bar(data, x="Jumlah", y="Kategori", orientation="h", color="Jumlah",
                     color_continuous_scale="Blues", text="Jumlah")
        fig.update_traces(textposition="outside")
    fig.update_layout(
        title=title,
        height=360,
        margin=dict(l=10, r=10, t=40, b=10),
        coloraxis_showscale=False,
        plot_bgcolor="#0b1220",
        paper_bgcolor="#0b1220",
        font_color="#e5e7eb",
    )
    return fig

def donut(series, title=""):
    """Donut chart untuk komposisi kategori."""
    data = series.reset_index()
    data.columns = ["Kategori", "Jumlah"]
    fig = px.pie(
        data, values="Jumlah", names="Kategori", hole=0.55,
        color="Kategori", color_discrete_sequence=px.colors.sequential.Blues_r
    )
    fig.update_layout(
        title=title,
        height=360,
        margin=dict(l=10, r=10, t=40, b=10),
        plot_bgcolor="#0b1220",
        paper_bgcolor="#0b1220",
        font_color="#e5e7eb",
        legend=dict(orientation="h", y=-0.15),
    )
    return fig

def spider_chart_from_counts(counts: pd.Series, title: str = "Spider Chart — Sumber Pegawai") -> go.Figure:
    """Spider/Radar chart dari Series hitungan kategori."""
    counts = counts.dropna()
    counts = counts[counts.index.astype(str).str.strip() != ""]
    counts = counts.sort_values(ascending=False)
    labels = counts.index.astype(str).tolist()
    values = counts.values.tolist()
    labels += labels[:1]; values += values[:1]
    max_r = max(values) if values else 1
    fig = go.Figure()
    fig.add_trace(go.Scatterpolar(r=values, theta=labels, fill="toself", mode="lines+markers", name="Jumlah"))
    fig.update_layout(
        title=title,
        showlegend=False,
        margin=dict(l=20, r=20, t=50, b=20),
        polar=dict(radialaxis=dict(visible=True, range=[0, max_r * 1.1]),
                   angularaxis=dict(direction="clockwise")),
        paper_bgcolor="#0b1220",
        font_color="#e5e7eb",
    )
    return fig

# =========================================
# LOAD DATA (READ-ONLY DB)
# =========================================
df_branch, df_employee, BRANCH_UNIT_COL, unit_map = load_data(DB_PATH)

# =========================================
# FEATURE HELPERS (peta & ringkasan)
# =========================================
def _initial_bearing(lat1, lon1, lat2, lon2) -> float:
    """Arah awal (derajat) dari titik A ke B. 0°=Utara, 90°=Timur."""
    φ1, φ2 = radians(lat1), radians(lat2)
    Δλ = radians(lon2 - lon1)
    x = sin(Δλ) * cos(φ2)
    y = cos(φ1)*sin(φ2) - sin(φ1)*cos(φ2)*cos(Δλ)
    return (degrees(atan2(x, y)) + 360) % 360

def _osrm_route(lat1, lon1, lat2, lon2, alternatives=True):
    """Ambil rute 'driving' dari OSRM (bisa multiple alternatif)."""
    url = (
        "https://router.project-osrm.org/route/v1/driving/"
        f"{lon1},{lat1};{lon2},{lat2}"
        f"?overview=full&geometries=geojson&steps=true&alternatives={'true' if alternatives else 'false'}"
    )
    r = requests.get(url, timeout=30)
    r.raise_for_status()
    data = r.json()
    if data.get("code") != "Ok":
        raise RuntimeError(data.get("message", "OSRM error"))
    return data["routes"]

def _fmt_time(sec: float) -> str:
    """Format detik → H j M m atau M m."""
    sec = int(sec); h, m = sec // 3600, (sec % 3600) // 60
    return f"{h} j {m} m" if h else f"{m} m"

def _fmt_time_range(sec: float, mult: float = 2.0) -> str:
    """Tampilkan kisaran waktu: OSRM → OSRM × mult (default 2x)."""
    return f"{_fmt_time(sec)} – {_fmt_time(sec * mult)}"

def _guess_leader_name(unit_norm: str) -> str:
    """Coba deteksi nama pimpinan unit dari df_branch; fallback: df_employee."""
    leader_cols = [
        "Pimpinan", "Nama Pimpinan", "Kepala Cabang",
        "Branch Manager", "Leader", "Nama Kepala Cabang"
    ]
    try:
        rows_b = df_branch[df_branch[BRANCH_UNIT_COL].astype(str).map(norm_txt) == unit_norm]
        for c in leader_cols:
            if c in df_branch.columns:
                val = rows_b[c].dropna().astype(str).str.strip()
                if not val.empty and val.iloc[0]:
                    return val.iloc[0]
    except Exception:
        pass

    try:
        cand = df_employee[df_employee["_unit_norm"] == unit_norm].copy()
        pos_col  = EMP_COLS.get("POSISI")
        stat_jbt = EMP_COLS.get("STATUS_JABATAN")
        name_col = EMP_COLS.get("NAMA")

        def looks_like_leader(x: str) -> bool:
            x = (x or "").lower()
            keys = ["pimpinan", "branch manager", "bm ", "bm-", "kepala cabang", "manager cabang"]
            return any(k in x for k in keys)

        if not cand.empty and name_col in cand.columns:
            mask = False
            if pos_col and pos_col in cand.columns:
                mask = cand[pos_col].astype(str).map(looks_like_leader)
            if stat_jbt and stat_jbt in cand.columns:
                mask = mask | cand[stat_jbt].astype(str).map(looks_like_leader) if isinstance(mask, pd.Series) else cand[stat_jbt].astype(str).map(looks_like_leader)
            top = cand[mask] if isinstance(mask, pd.Series) else pd.DataFrame()
            if not top.empty:
                return str(top.iloc[0][name_col])
    except Exception:
        pass

    return "—"

def _unit_stats(unit_norm: str):
    """Hitung total pegawai, L, P, dan 3 status pegawai teratas pada sebuah unit."""
    sub = df_employee[df_employee["_unit_norm"] == unit_norm]
    total = int(len(sub))
    male = female = 0

    gcol = EMP_COLS.get("GENDER")
    if gcol and gcol in sub.columns:
        g = sub[gcol].astype(str).str.strip().str.lower()
        male   = int((g.isin(["male", "laki-laki", "laki", "pria"])).sum())
        female = int((g.isin(["female", "perempuan", "wanita"])).sum())

    scol = EMP_COLS.get("STATUS_PEGAWAI")
    top_status = []
    if scol and scol in sub.columns and not sub.empty:
        vc = sub[scol].astype(str).str.strip().value_counts().head(3)
        top_status = [(k, int(v)) for k, v in vc.items()]

    return total, male, female, top_status

def _link_setpoint(kind: str, unit: str) -> str:
    """Bangun link set start/end pada halaman map."""
    safe_unit = quote_plus(unit)
    base = _base_prefix()
    if kind == "start":
        return f"{base}/?route=map&start_unit={safe_unit}"
    return f"{base}/?route=map&end_unit={safe_unit}"

# =========================================
# PAGES
# =========================================
def page_distribution_branch():
    """Halaman peta interaktif: cari cabang (zoom), pilih area (zoom), dan rute Start→End."""
    if df_branch.empty:
        st.info("Tidak ada data cabang di database.")
        return

    # ===== Session defaults =====
    st.session_state.setdefault("start_unit", None)
    st.session_state.setdefault("end_unit", None)
    st.session_state.setdefault("selected_unit", None)     # pilihan hasil search kiri
    st.session_state.setdefault("map_focus", None)         # (lat, lon) untuk zoom fokus
    st.session_state.setdefault("map_zoom", 9)             # zoom untuk map_focus
    st.session_state.setdefault("suppress_route", False)   # True = jangan gambar route

    st.subheader("🗺️ Distribution Branch — Peta Interaktif")

    # ===== Data & checks =====
    bcol = BRANCH_UNIT_COL or "Unit/Kantor"
    need_cols = {"Latitude", "Longitude"}
    if bcol not in df_branch.columns:
        st.error(f"Kolom unit '{bcol}' tidak ada di df_branch.")
        return
    if not need_cols.issubset(df_branch.columns):
        st.error("Kolom 'Latitude' dan 'Longitude' wajib ada.")
        return

    dfb = df_branch.dropna(subset=["Latitude", "Longitude"]).copy()
    if dfb.empty:
        st.warning("Semua baris cabang tidak memiliki koordinat yang valid.")
        return

    # ===== util =====
    def _row_by_unit(u: str):
        """Ambil satu baris cabang berdasarkan nama unit eksak."""
        if not u: return None
        r = dfb[dfb[bcol].astype(str) == str(u)]
        return None if r.empty else r.iloc[0]

    # ====== LAYOUT: kiri (search & area) | kanan (start-end) ======
    hL, hR = st.columns([1, 1])

    # ---- hL: search cabang + tombol area ----
    with hL:
        st.markdown("**🔎 Cari Cabang (zoom)**")
        opt0 = "— pilih cabang untuk zoom —"
        cabang_opts = [opt0] + sorted(dfb[bcol].dropna().astype(str).unique().tolist())
        sel_cabang = st.selectbox(
            "Pilih cabang", cabang_opts, index=0, key="sb_left_select",
            placeholder="Pilih atau ketik untuk mencari…",
        )
        if sel_cabang != opt0:
            hit = dfb[dfb[bcol].astype(str) == sel_cabang].iloc[0]
            st.session_state["selected_unit"] = str(hit[bcol])
            # Aksi kiri = fokus lokasi & hapus rute
            st.session_state["start_unit"] = None
            st.session_state["end_unit"]   = None
            st.session_state["suppress_route"] = True
            st.session_state["map_focus"] = (float(hit["Latitude"]), float(hit["Longitude"]))
            st.session_state["map_zoom"]  = 20
            st.success(f"Zoom ke: {hit[bcol]}")

        # Tombol area dari pusat mean lat/lon
        area_col = "AREA" if "AREA" in dfb.columns else None
        if area_col:
            grp = (
                dfb.groupby(dfb[area_col].astype(str), dropna=True)
                   .agg(lat=("Latitude", "mean"), lon=("Longitude", "mean"))
                   .reset_index()
            ).sort_values(area_col)
            bcols = st.columns(len(grp)) if len(grp) else []
            for (i, r) in enumerate(grp.itertuples(index=False)):
                label = str(getattr(r, area_col))
                with bcols[i]:
                    if st.button(label, key=f"btn_area_{label}", use_container_width=True):
                        st.session_state["selected_unit"] = None
                        st.session_state["start_unit"] = None
                        st.session_state["end_unit"]   = None
                        st.session_state["suppress_route"] = True
                        st.session_state["map_focus"] = (float(r.lat), float(r.lon))
                        st.session_state["map_zoom"]  = 10

        st.caption("Catatan: aksi di kiri **menghapus rute** yang sedang tampil agar fokus pada lokasi.")

    # ---- hR: pilih start & tujuan ----
    units = dfb[bcol].dropna().astype(str).drop_duplicates().tolist()
    opt0 = "— pilih —"
    with hR:
        st.markdown("**🚦 Rute (Start → Tujuan)**")
        idx_s = units.index(st.session_state["start_unit"]) + 1 if st.session_state["start_unit"] in units else 0
        idx_e = units.index(st.session_state["end_unit"]) + 1 if st.session_state["end_unit"] in units else 0
        start_sel = st.selectbox("Titik awal", [opt0] + units, index=idx_s, key="sb_start_sel")
        end_sel   = st.selectbox("Tujuan",     [opt0] + units, index=idx_e, key="sb_end_sel")

        if start_sel != opt0:
            st.session_state["start_unit"] = start_sel
            st.session_state["suppress_route"] = False
        if end_sel != opt0:
            st.session_state["end_unit"] = end_sel
            st.session_state["suppress_route"] = False

        if st.button("🧹 Bersihkan Rute", use_container_width=True):
            st.session_state["start_unit"] = None
            st.session_state["end_unit"]   = None
            st.session_state["suppress_route"] = True
            st.session_state["map_focus"] = None

    # ====== baris data terpilih ======
    row_start = _row_by_unit(st.session_state.get("start_unit"))
    row_end   = _row_by_unit(st.session_state.get("end_unit"))

    # ====== Siapkan peta ======
    center_lat = float(dfb["Latitude"].mean())
    center_lon = float(dfb["Longitude"].mean())
    zoom_lvl   = 7

    mf = st.session_state.get("map_focus")
    if mf:
        center_lat, center_lon = mf[0], mf[1]
        zoom_lvl = st.session_state.get("map_zoom", 12)

    fmap = folium.Map(location=[center_lat, center_lon], zoom_start=zoom_lvl, tiles="CartoDB positron")

    # ====== Marker semua cabang ======
    for _, row in dfb.iterrows():
        unit = str(row.get(bcol, "") or "")
        lat, lon = float(row["Latitude"]), float(row["Longitude"])
        area_val = _row_area_value(row)
        marker_color = _area_to_color(area_val)

        if (row_start is not None) and (unit == str(row_start[bcol])):
            icon = folium.Icon(color=marker_color, icon="play", prefix="fa")
        elif (row_end is not None) and (unit == str(row_end[bcol])):
            icon = folium.Icon(color=marker_color, icon="flag-checkered", prefix="fa")
        else:
            icon = folium.Icon(color=marker_color, icon="building", prefix="fa")

        # Popup isi ringkas + aksi
        nama_kantor  = unit
        kode_cabang  = str(row.get("KODE_CABANG", "—"))
        unit_kerja   = str(row.get("Unit Kerja", "—"))
        kelas_cabang = str(row.get("Kelas Cabang", "—"))
        izin_bi      = str(row.get("Izin BI", "—"))
        status_ged   = str(row.get("Status Gedung", "—"))
        kota_kab     = str(row.get("Kota/Kab.", "—"))
        area_str     = str(row.get("AREA", "—"))
        leader_name  = _guess_leader_name(row["_unit_norm"])
        tot, m_cnt, f_cnt, _ = _unit_stats(row["_unit_norm"])
        gmaps_url    = f"https://maps.google.com/?q={lat},{lon}"
        detail_url   = f"?route=detailb&unit={quote_plus(unit)}"
        set_start    = _link_setpoint("start", unit)
        set_end      = _link_setpoint("end", unit)

        popup_html = f"""
        <div data-unit="{unit}" style="font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, Helvetica, Arial;
        color:#0f172a; min-width:260px; max-width:340px;">
        <div style="background:linear-gradient(135deg,#e0f2fe 0%, #dbeafe 60%, #eef2ff 100%);
                    border:1px solid #cbd5e1; border-radius:14px; padding:12px;">
            <div style="display:flex; gap:8px; align-items:center; margin-bottom:8px;">
            <div style="width:34px; height:34px; border-radius:10px; background:#1d4ed8; display:flex; align-items:center; justify-content:center; color:white; font-weight:700;">🏢</div>
            <div>
                <div style="font-size:14px; color:#334155;">Nama Kantor</div>
                <div style="font-size:15px; font-weight:800; color:#0f172a;">{nama_kantor}</div>
            </div>
            </div>

            <div style="background:#ffffff; border:1px solid #e2e8f0; border-radius:12px; padding:10px; margin-bottom:10px;">
            <div style="font-size:12px; color:#475569; margin-bottom:4px;">📌 Informasi Cabang</div>
            <div style="font-size:12px;"><b>Nama Kode Cabang:</b> {kode_cabang}</div>
            <div style="font-size:12px;"><b>Unit Kerja:</b> {unit_kerja}</div>
            <div style="font-size:12px;"><b>Kelas Cabang:</b> {kelas_cabang}</div>
            <div style="font-size:12px;"><b>Izin BI:</b> {izin_bi}</div>
            <div style="font-size:12px;"><b>Status Gedung:</b> {status_ged}</div>
            <div style="font-size:12px;"><b>Kota/Kab.:</b> {kota_kab}</div>
            <div style="font-size:12px;"><b>AREA:</b> {area_str}</div>
            </div>

            <div style="margin:6px 0 10px 0; padding:8px; background:#ffffffcc; border:1px solid #e2e8f0; border-radius:10px;">
            <div style="font-size:12px; color:#334155;">Pimpinan</div>
            <div style="font-size:14px; font-weight:700;">{leader_name}</div>
            </div>

            <div style="display:grid; grid-template-columns:repeat(3,minmax(0,1fr)); gap:6px;">
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px; text-align:center;">
                <div style="font-size:11px; color:#475569;">Total</div>
                <div style="font-size:16px; font-weight:800;">{tot}</div>
            </div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px; text-align:center;">
                <div style="font-size:11px; color:#475569;">👨 L</div>
                <div style="font-size:16px; font-weight:800;">{m_cnt}</div>
            </div>
            <div style="background:#fff; border:1px solid #e2e8f0; border-radius:10px; padding:8px; text-align:center;">
                <div style="font-size:11px; color:#475569;">👩 P</div>
                <div style="font-size:16px; font-weight:800;">{f_cnt}</div>
            </div>
            </div>

            <div style="display:flex; gap:6px; margin-top:10px; flex-wrap:wrap;">
            <a href="{gmaps_url}" target="_blank" rel="noopener" style="text-decoration:none; font-weight:700; font-size:12px; padding:8px 10px; border-radius:10px; border:1px solid #94a3b8; background:#ffffff; color:#0f172a;">📍 Google Maps</a>
            <a href="{detail_url}" target="_top" rel="noopener" style="text-decoration:none; font-weight:700; font-size:12px; padding:8px 10px; border-radius:10px; border:1px solid #3b82f6; background:#1d4ed8; color:white;">🔎 Detail Branch</a>
            <a href="{set_start}" target="_top" rel="noopener" style="text-decoration:none; font-weight:700; font-size:12px; padding:8px 10px; border-radius:10px; border:1px dashed #10b981; background:#ecfdf5; color:#065f46;">▶️ Jadikan Start</a>
            <a href="{set_end}" target="_top" rel="noopener" style="text-decoration:none; font-weight:700; font-size:12px; padding:8px 10px; border-radius:10px; border:1px dashed #ef4444; background:#fef2f2; color:#991b1b;">🏁 Jadikan Tujuan</a>
            </div>
        </div>
        </div>
        """

        folium.Marker(
            location=[lat, lon],
            tooltip=f"🏢 {unit}",
            icon=icon,
            popup=folium.Popup(popup_html, max_width=400)
        ).add_to(fmap)

    # ====== Gambar rute jika ada start & end dan tak disuppress ======
    routes_info = []
    if (not st.session_state.get("suppress_route", False)) and (row_start is not None) and (row_end is not None):
        lat1, lon1 = float(row_start["Latitude"]), float(row_start["Longitude"])
        lat2, lon2 = float(row_end["Latitude"]), float(row_end["Longitude"])
        try:
            routes = _osrm_route(lat1, lon1, lat2, lon2, alternatives=True) or []
            for i, r in enumerate(routes, 1):
                dist_km = r["distance"] / 1000.0
                dur_s = r["duration"]
                coords = r["geometry"]["coordinates"]
                latlon = [(c[1], c[0]) for c in coords]
                routes_info.append((i, dist_km, dur_s, r))
                folium.PolyLine(
                    latlon, weight=6, opacity=0.9,
                    tooltip=f"Rute {i}: {dist_km:.1f} km, {_fmt_time_range(dur_s)}"
                ).add_to(fmap)
            # fokuskan ke bounding box start-end
            try:
                fmap.fit_bounds([(lat1, lon1), (lat2, lon2)])
            except Exception:
                pass
        except Exception as e:
            st.error(f"Gagal ambil rute dari OSRM: {e}")

    # Render peta
    st_folium(fmap, width=1800, height=540)

    # Ringkasan rute / info pilihan
    if routes_info and (row_start is not None) and (row_end is not None):
        bearing = _initial_bearing(float(row_start["Latitude"]), float(row_start["Longitude"]),
                                   float(row_end["Latitude"]),   float(row_end["Longitude"]))
        st.success(f"**{row_start[bcol]} → {row_end[bcol]}** • Arah awal: {bearing:.0f}° • {len(routes_info)} alternatif.")
        st.caption("⏱️ Waktu ditampilkan sebagai kisaran: estimasi OSRM → OSRM×2.")
    elif st.session_state.get("start_unit") or st.session_state.get("end_unit"):
        st.info(f"Start: **{st.session_state.get('start_unit') or '—'}** • Tujuan: **{st.session_state.get('end_unit') or '—'}** — pilih keduanya untuk menampilkan rute.")

def page_detail_branch():
    """Halaman detail pegawai pada sebuah unit (dipilih via dropdown / klik marker dari peta)."""
    if df_branch.empty or df_employee.empty or not BRANCH_UNIT_COL:
        st.info("Data belum tersedia.")
        return

    # Kumpulan unit untuk dropdown
    all_units = (
        df_branch[BRANCH_UNIT_COL].dropna().astype(str).map(str.strip).unique().tolist()
    )
    all_units = sorted([u for u in all_units if u])

    st.subheader("📌 Detail Pegawai – Unit Dipilih")
    option_placeholder = "— (pilih dari klik peta) —"

    manual_unit = st.selectbox("🏢 Pilih Unit", options=[option_placeholder] + all_units, index=0)

    selected_unit_display = st.session_state.get("selected_unit")
    if manual_unit != option_placeholder:
        if manual_unit != selected_unit_display:
            st.session_state["selected_unit"] = manual_unit
            goto("detail", unit=manual_unit)
        else:
            selected_unit_display = manual_unit

    if not selected_unit_display:
        st.info("Pilih unit dari dropdown atau klik marker di tab **Distribution Branch**.")
        return

    st.markdown(f"### 🏦 {selected_unit_display}")

    unit_norm = norm_txt(selected_unit_display)
    df_filtered = df_employee[df_employee["_unit_norm"] == unit_norm].copy()

    # Filter bar atas (Gender, Status, Status Jabatan)
    k1, k2 = st.columns(2)
    with k1:
        st.markdown(
            f'<div class="kpi"><div class="title">👥 Total Pegawai (unit)</div>'
            f'<div class="val">{len(df_filtered):,}</div>'
            f'<div class="sub">Unit: {selected_unit_display}</div></div>',
            unsafe_allow_html=True,
        )
    with k2:
        k2a, k2b, k2c = st.columns(3)
        with k2a:
            sel_gender = st.multiselect(
                "⚧️ Gender",
                sorted(
                    df_employee.get(EMP_COLS["GENDER"], pd.Series(dtype=str))
                    .dropna().astype(str).unique().tolist()
                )
            )
        with k2b:
            sel_status = st.multiselect(
                "🧾 Status Pegawai",
                sorted(
                    df_employee.get(EMP_COLS["STATUS_PEGAWAI"], pd.Series(dtype=str))
                    .dropna().astype(str).unique().tolist()
                )
            )
        with k2c:
            sel_status_jbt = st.multiselect(
                "🏷️ Status Jabatan",
                sorted(
                    df_employee.get(EMP_COLS["STATUS_JABATAN"], pd.Series(dtype=str))
                    .dropna().astype(str).unique().tolist()
                )
            )

    # Terapkan filter
    if sel_gender:
        df_filtered = df_filtered[df_filtered.get(EMP_COLS["GENDER"], "").isin(sel_gender)]
    if sel_status:
        df_filtered = df_filtered[df_filtered.get(EMP_COLS["STATUS_PEGAWAI"], "").isin(sel_status)]
    if sel_status_jbt:
        df_filtered = df_filtered[df_filtered.get(EMP_COLS["STATUS_JABATAN"], "").isin(sel_status_jbt)]

    if df_filtered.empty:
        st.warning("❌ Belum ada data pegawai untuk unit ini (atau tidak lolos filter).")
        return

    # KPI mini gender
    gcount = df_filtered.get("Gender", pd.Series([], dtype=str)).astype(str).str.strip().value_counts()
    male = int(gcount.get("Male", 0) + gcount.get("Laki-laki", 0))
    female = int(gcount.get("Female", 0) + gcount.get("Perempuan", 0))
    mcol1, mcol2, mcol3 = st.columns(3)
    mcol1.metric("Total Pegawai", len(df_filtered))
    mcol2.metric("Laki-laki 👨", male)
    mcol3.metric("Perempuan 👩", female)

    # Tabel ringkas + tombol 'Lihat'
    cols_show = [c for c in [EMP_COLS["NIP"], EMP_COLS["NAMA"], EMP_COLS["GENDER"], EMP_COLS["POSISI"], EMP_COLS["STATUS_PEGAWAI"]] if c in df_filtered.columns]
    tbl = df_filtered[cols_show].copy() if cols_show else df_filtered.copy()

    def _btn_emp(row) -> str:
        url = link_employee_detail_row(row, EMP_COLS["NIP"], EMP_COLS["NAMA"])
        return f'<a href="{url}" target="_top" class="btn-emp">🔎 Lihat</a>'

    tbl["Aksi"] = df_filtered.apply(_btn_emp, axis=1)

    st.markdown("""
    <style>
    .btn-emp{
      display:inline-block; padding:6px 12px; border-radius:10px;
      border:1px solid var(--border); text-decoration:none; color:#e5e7eb;
      background:linear-gradient(135deg,#0ea5e9 0%, #1d4ed8 60%, #0f172a 100%);
      box-shadow:0 4px 12px var(--shadow); font-weight:700; white-space:nowrap;
    }
    .btn-emp:hover{ filter:brightness(1.1); }
    table.dataframe { width:100%; background:var(--panel); color:#e5e7eb;
      border:1px solid var(--border); border-collapse:collapse; }
    table.dataframe th, table.dataframe td{
      border:1px solid var(--border); padding:8px 10px; vertical-align:middle;
    }
    table.dataframe thead th{ background:rgba(148,163,184,.08); }
    </style>
    """, unsafe_allow_html=True)

    st.markdown(tbl.to_html(index=False, escape=False), unsafe_allow_html=True)

    v1, v2 = st.columns(2)
    with v1:
        if EMP_COLS["SOURCE"] in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🕸️ Spider Chart — Source Pegawai")
            source_count = df_filtered[EMP_COLS["SOURCE"]].astype(str).str.strip()\
                .replace({"nan": None, "NaN": None, "": None}).dropna().value_counts()
            st.plotly_chart(spider_chart_from_counts(source_count), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
        if EMP_COLS["GENDER"] in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### ⚧️ Gender")
            gcount2 = df_filtered[EMP_COLS["GENDER"]].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(gcount2), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    with v2:
        if EMP_COLS["STATUS_PEGAWAI"] in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🧾 Status Pegawai")
            status_count = df_filtered[EMP_COLS["STATUS_PEGAWAI"]].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(status_count), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
        if EMP_COLS["STATUS_JABATAN"] in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🏷️ Status Jabatan")
            sj_count = df_filtered[EMP_COLS["STATUS_JABATAN"]].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(sj_count.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

def page_detail_employee():
    """Halaman profil pegawai (IG-style) + matriks PL/TC + statistik karir."""
    if df_employee.empty:
        st.info("Data pegawai belum tersedia.")
        return

    st.subheader("🧑‍💼 Detail Employee")

    # Kolom sesuai skema tetap
    name_col = emp(df_employee, "NAMA")
    id_col   = emp(df_employee, "NIP")
    unit_col = emp(df_employee, "UNIT")
    pos_col  = emp(df_employee, "POSISI")
    gen_col  = emp(df_employee, "GENDER")
    stat_col = emp(df_employee, "STATUS_PEGAWAI")
    dob_col  = emp(df_employee, "BIRTHDATE")

    # Deteksi kolom foto (jika ada)
    _photo_cands = {"foto","photo","image","img","url_foto","link_foto","photo_url","foto_url","link foto","avatar"}
    photo_col = next((c for c in df_employee.columns if str(c).strip().lower() in _photo_cands), None)

    # Preselect dari query param
    sel_nip = qp.get("nip")
    sel_nama = qp.get("nama")

    # Filter ringkas: Unit + quick search (Nama/NIP)
    units = []
    if unit_col:
        units = (
            df_employee[unit_col].dropna().astype(str).map(str.strip).drop_duplicates().sort_values().tolist()
        )
    opt_all = "Semua"

    pref_unit = qp.get("unit") or st.session_state.get("selected_unit")
    units_list = [opt_all] + units if units else [opt_all]
    idx_unit = 0
    if unit_col and pref_unit and units and pref_unit in units:
        idx_unit = units.index(pref_unit) + 1

    colA, colB = st.columns([1, 1])
    with colA:
        unit_pick = st.selectbox("🏢 Filter Unit", units_list, index=idx_unit, key="emp_unit_pick")
    with colB:
        q = st.text_input("🔎 Cari cepat (Nama/NIP)", value=sel_nip or sel_nama or "")

    df_e2 = df_employee.copy()
    if unit_col and unit_pick != opt_all:
        df_e2 = df_e2[df_e2[unit_col].astype(str).str.strip() == unit_pick]

    if q:
        qn = q.strip().casefold()
        mask = False
        if name_col:
            mask = mask | df_e2[name_col].astype(str).str.casefold().str.contains(qn, na=False)
        if id_col:
            mask = mask | df_e2[id_col].astype(str).str.casefold().str.contains(qn, na=False)
        df_e2 = df_e2[mask] if isinstance(mask, pd.Series) else df_e2

    if df_e2.empty:
        st.warning("Tidak ada pegawai yang cocok dengan filter.")
        return

    def _label_row(r: pd.Series):
        nm = str(r[name_col]) if name_col else "(tanpa nama)"
        nip = str(r[id_col]) if id_col and pd.notna(r[id_col]) else "—"
        un = str(r[unit_col]) if unit_col and pd.notna(r[unit_col]) else "—"
        return f"{nm} · {nip} · {un}"

    options = df_e2.index.tolist()
    labels = [_label_row(df_e2.loc[i]) for i in options]

    def _pref_index_from_qp():
        if id_col and sel_nip:
            m = df_e2[df_e2[id_col].astype(str) == str(sel_nip)]
            if not m.empty:
                return options.index(m.index[0])
        if name_col and sel_nama:
            m = df_e2[df_e2[name_col].astype(str) == str(sel_nama)]
            if not m.empty:
                return options.index(m.index[0])
        return 0

    if "emp_select" not in st.session_state or st.session_state.get("_emp_options_len") != len(options):
        st.session_state.emp_select = _pref_index_from_qp()
        st.session_state._emp_options_len = len(options)

    csel, _, _ = st.columns([0.7, 0.15, 0.15])
    with csel:
        st.selectbox(
            "👤 Pilih Pegawai",
            options=range(len(options)),
            index=st.session_state.emp_select,
            format_func=lambda i: labels[i],
            key="emp_select",
        )

    row = df_e2.loc[options[st.session_state.emp_select]]

    # ---------- IG card ----------
    st.markdown('<div class="ig-card">', unsafe_allow_html=True)
    st.markdown('<div class="ig-header">', unsafe_allow_html=True)

    hL, hR = st.columns([0.28, 0.72], vertical_alignment="center")

    # ===== Bagian kiri: avatar + info ringkas =====
    with hL:
        # CSS mini untuk blok kiri
        st.markdown("""
        <style>
        .prof-wrap{display:flex; gap:14px; align-items:center}
        .prof-name{font-size:1.05rem; font-weight:900; line-height:1.2}
        .prof-sub{color:#94a3b8; font-size:.9rem}
        .chips{display:flex; flex-wrap:wrap; gap:8px; margin:8px 0 4px}
        .chip{padding:6px 10px; border-radius:999px; border:1px solid rgba(148,163,184,.25);
                background:rgba(148,163,184,.08); font-size:.85rem; font-weight:700}
        .chip.pos{border-color:#8b5cf6aa; background:#8b5cf633}
        .chip.unit{border-color:#0ea5e9aa; background:#0ea5e933}
        .chip.sts{border-color:#22c55eaa; background:#22c55e33}
        .chip.gen{border-color:#f59e0baa; background:#f59e0b33}
        .chip.grade{border-color:#60a5faaa; background:#60a5fa33}
        .ig-grid-mini{display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:8px; margin-top:10px}
        .row{border:1px solid rgba(148,163,184,.18); background:rgba(148,163,184,.06);
            border-radius:12px; padding:8px 10px}
        .k{color:#cbd5e1; font-weight:700; font-size:.85rem}
        .v{font-size:.95rem}
        @media (max-width: 980px){ .ig-grid-mini{grid-template-columns:1fr} }
        </style>
        """, unsafe_allow_html=True)

        # Avatar
        photo = photo_url_from_row(row, id_col, photo_col)
        # jika ada foto dan itu status pegawainya bukan "Kriya" atau "TAD"
        if photo and stat_col and str(row.get(stat_col, "")).strip().lower() not in {"kriya","tad"}:
            st.markdown(f'<img src="{photo}" class="ig-avatar" alt="foto">', unsafe_allow_html=True)
        else:
            st.markdown('<div class="ig-avatar" style="display:grid;place-items:center;font-size:2rem;background:rgba(148,163,184,.08)">👤</div>', unsafe_allow_html=True)

        # Helper get kolom aman
        def g(colname):
            if not colname or colname not in df_employee.columns: return None
            v = row.get(colname, None)
            if pd.isna(v): return None
            s = str(v).strip()
            return s if s else None

        # Data kunci
        nama   = str(row[name_col]) if name_col else "(tanpa nama)"
        unit   = g(EMP_COLS["UNIT"])
        pos    = g(EMP_COLS["POSISI"])
        gender = g(EMP_COLS["GENDER"])
        status = g(EMP_COLS["STATUS_PEGAWAI"])
        dep    = row[EMP_COLS["DEP"]] if has_emp(df_employee, "DEP") else None
        area   = row[EMP_COLS["AREA"]] if has_emp(df_employee, "AREA") else None
        sjab   = row[EMP_COLS["STATUS_JABATAN"]] if has_emp(df_employee, "STATUS_JABATAN") else None
        age    = _calc_age(_safe_get(row, EMP_COLS["BIRTHDATE"]))
        grade       = g("Grade")
        job_grade   = g("Job Grade")
        band        = g("Band")
        level       = g("Level")
        kelas_cb    = g("Kelas Cabang")
        kode_cb     = g("Kode Cabang")
        homebase    = g("Homebase")
        work_ct     = g("Work Contract")
        corp_title  = g("Corp. Title")
        job_level   = g("Job Level")
        generasi    = g("Generasi") or g("Detail12")
        birth       = g("Birthdate")
        agama       = g("Agama")

        # Header teks
        st.markdown(f"""
        <div class="prof-wrap">
          <div>
            <div class="prof-name">{nama if nama else "(tanpa nama)"}</div>
            <div class="prof-sub">{unit or ""}</div>
          </div>
        </div>
        """, unsafe_allow_html=True)

        chips_html = []
        if pos:           chips_html.append(f"<span class='chip pos'>🧑‍💼 {pos}</span>")
        if unit:          chips_html.append(f"<span class='chip unit'>🏢 {unit}</span>")
        if status:        chips_html.append(f"<span class='chip sts'>🪪 {status}</span>")
        if sjab:          chips_html.append(f"<span class='chip sts'>📌 {sjab}</span>")
        if gender:        chips_html.append(f"<span class='chip gen'>⚧ {gender}</span>")
        gb = " · ".join([x for x in [grade, job_grade, band, level] if x])
        if gb:            chips_html.append(f"<span class='chip grade'>⭐ {gb}</span>")
        if chips_html:
            st.markdown("<div class='chips'>" + "".join(chips_html) + "</div>", unsafe_allow_html=True)

        def row_kv(k, v):
            if not v: return ""
            return f"<div class='row'><div class='k'>{k}</div><div class='v'>{v}</div></div>"

        def fmt_date(s):
            if not s: return None
            d = pd.to_datetime(s, errors="coerce")
            return None if pd.isna(d) else d.strftime("%d %b %Y")

        info_blocks = [
            row_kv("Dep", dep),
            row_kv("Area", area),
            row_kv("Kelas/Kode Cabang", " / ".join([x for x in [kelas_cb, kode_cb] if x])),
            row_kv("Homebase", homebase),
            row_kv("Work Contract", work_ct),
            row_kv("Corp. Title", corp_title),
            row_kv("Job Level", job_level),
            row_kv("Agama", agama),
            row_kv("Birthdate / Usia", f"{fmt_date(birth) or '-'}  •  {age} th" if age is not None else (fmt_date(birth) or None)),
        ]
        info_blocks = [b for b in info_blocks if b]
        if info_blocks:
            st.markdown("<div class='ig-grid-mini'>" + "".join(info_blocks) + "</div>", unsafe_allow_html=True)

        # Mini stats
        st.markdown('<div class="ig-stats">', unsafe_allow_html=True)
        def stat(label, val):
            st.markdown(
                f'<div class="ig-stat"><span class="n">{(val if val not in (None,"","nan","NaT") else "—")}</span>'
                f'<span class="l" style="margin-left:6px;color:#94a3b8">{label}</span></div>',
                unsafe_allow_html=True
            )
        stat("Unit", unit)
        stat("Posisi", pos)
        stat("Status", status)
        st.markdown('</div>', unsafe_allow_html=True)

    # ===== Bagian kanan: PL/TC + statistik karir =====
    with hR:
        st.markdown("#### Statistik")

        st.markdown("""
        <style>
        .mx {width:100%; border-collapse:separate; border-spacing:0; overflow:hidden;
            border:1px solid rgba(148,163,184,.18); border-radius:14px}
        .mx th,.mx td{ padding:12px 14px; text-align:center; color:#e5e7eb;
                        border-bottom:1px solid rgba(148,163,184,.12) }
        .mx thead th{ background:linear-gradient(180deg, rgba(148,163,184,.18), rgba(148,163,184,.10));
                        font-weight:800 }
        .mx tbody tr:last-child td{ border-bottom:none }
        .mx th:first-child,.mx td:first-child{ text-align:left; width:110px; font-weight:800; color:#cbd5e1 }
        .pill-b{display:inline-block; padding:6px 12px; border-radius:999px; font-weight:800;
                border:1px solid #60a5faaa; background:#60a5fa33}
        .pill-o{display:inline-block; padding:6px 12px; border-radius:999px; font-weight:800;
                border:1px solid #f59e0baa; background:#f59e0b33}
        .pill-muted{opacity:.65; border:1px dashed rgba(148,163,184,.35); background:rgba(148,163,184,.07)}
        .legend{display:flex;gap:10px;color:#94a3b8;font-size:.85rem;margin:6px 0 10px}
        .legend .dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:6px}
        .dot.pl{background:#60a5fa}.dot.tc{background:#f59e0b}

        .cardtbl{width:100%; border-collapse:separate; border-spacing:0; overflow:hidden;
                border:1px solid rgba(148,163,184,.18); border-radius:14px; margin-top:14px}
        .cardtbl th,.cardtbl td{ padding:12px 14px; border-bottom:1px solid rgba(148,163,184,.12) }
        .cardtbl thead th{ background:linear-gradient(180deg, rgba(148,163,184,.18), rgba(148,163,184,.10));
                            color:#e5e7eb; font-weight:900; text-align:center }
        .cardtbl tbody tr:nth-child(odd) td{ background:rgba(148,163,184,.05) }
        .cardtbl tbody tr:last-child td{ border-bottom:none }
        .col-key{ width:42%; color:#cbd5e1; font-weight:700 }
        .val-badge{ display:inline-block; padding:6px 12px; border-radius:10px; background:rgba(148,163,184,.10);
                    border:1px solid rgba(148,163,184,.22) }
        @media (max-width: 980px){ .col-key{ width:48% } }
        </style>
        """, unsafe_allow_html=True)

        def read(col):
            if col not in df_employee.columns: return None
            v = row.get(col, None)
            return None if pd.isna(v) else str(v).strip()

        def fmt_date(s):
            if not s: return None
            d = pd.to_datetime(s, errors="coerce")
            return None if pd.isna(d) else d.strftime("%d %b %Y")  # 01 Feb 2045

        def years_to_ybh(x):
            """Konversi 'tahun desimal' -> 'X Tahun Y Bulan Z Hari' (aproksimasi)."""
            if x in (None, "", "nan", "NaN"): return None
            try:
                f = float(str(x).replace(",", "."))
            except Exception:
                return str(x)
            if f < 0: f = 0.0
            total_days = int(round(f * 365))
            th = total_days // 365
            rem = total_days % 365
            bln = rem // 30
            hr  = rem % 30
            parts = []
            if th > 0:  parts.append(f"{th} Tahun")
            if bln > 0: parts.append(f"{bln} Bulan")
            if hr > 0 or not parts: parts.append(f"{hr} Hari")
            return " ".join(parts)

        # Matriks PL/TC (2022–2024) — tanpa 'nan', fallback '-'
        years = ["2022", "2023", "2024"]

        def _disp(v: object) -> str:
            """Normalisasi tampilan: None/kosong/nan/NaN/NaT -> '-'."""
            s = "" if v is None else str(v).strip()
            if not s or s.lower() in {"nan", "nat", "none", "null"}:
                return "—"
            return s

        pl_vals = [_disp(read(f"PL-{y}")) for y in years]
        tc_vals = [_disp(read(f"TC-{y}")) for y in years]

        header = "".join(f"<th>{y}</th>" for y in years)
        row_pl = "".join(
            f"<td><span class='pill-b'>{v}</span></td>" if v != "-" else "<td><span class='pill-muted'>-</span></td>"
            for v in pl_vals
        )
        row_tc = "".join(
            f"<td><span class='pill-o'>{v}</span></td>" if v != "-" else "<td><span class='pill-muted'>-</span></td>"
            for v in tc_vals
        )

        st.markdown(
            "<div class='legend'><span><i class='dot pl'></i>PL</span><span><i class='dot tc'></i>TC</span></div>",
            unsafe_allow_html=True
        )
        st.markdown(f"""
        <table class="mx">
        <thead><tr><th></th>{header}</tr></thead>
        <tbody>
            <tr><th>PL</th>{row_pl}</tr>
            <tr><th>TC</th>{row_tc}</tr>
        </tbody>
        </table>
        """, unsafe_allow_html=True)

        # Statistik karir
        fields = [
            ("TMT Grade",       fmt_date(read("TMT Grade")) or read("TMT Grade")),
            ("Lama Grade",      years_to_ybh(read("Length Grade"))),
            ("TMT Posisi",      fmt_date(read("TMT Posisi")) or read("TMT Posisi")),
            ("Lama Posisi",     years_to_ybh(read("Length Posisi"))),
            ("TMT Group",       fmt_date(read("TMT Group")) or read("TMT Group")),
            ("Lama Group",      years_to_ybh(read("Length Group"))),
            ("TMT Lokasi",      fmt_date(read("TMT Lokasi")) or read("TMT Lokasi")),
            ("Lama Lokasi",     years_to_ybh(read("Length Lokasi"))),
            ("Sisa Masa Kerja", years_to_ybh(read("Sisa Masa Kerja"))),
            ("Usia Pensiun",    read("Usia Pensiun")),
            ("Tanggal Pensiun", fmt_date(read("Tanggal Pensiun")) or read("Tanggal Pensiun")),
        ]

        body = "".join(
            f"<tr><td class='col-key'>{k}</td><td><span class='val-badge'>{(v if v not in (None,'','nan','NaT') else '—')}</span></td></tr>"
            for k, v in fields
        )
        st.markdown(f"""
        <table class="cardtbl">
          <thead><tr><th>Statistik Karir</th><th>Nilai</th></tr></thead>
          <tbody>{body}</tbody>
        </table>
        """, unsafe_allow_html=True)

    st.markdown('</div>', unsafe_allow_html=True)  # /ig-header
    st.markdown('</div>', unsafe_allow_html=True)  # /ig-card

def page_dashboard():
    """Halaman ringkasan keseluruhan: KPI, chart, dan tabel jumlah pegawai per unit (filter Area)."""
    if df_branch.empty or df_employee.empty or BRANCH_UNIT_COL is None:
        st.info("Data belum lengkap di database.")
        return

    hdr_left, hdr_right = st.columns([0.72, 0.28])
    with hdr_left:
        st.subheader("📊 Dashboard — Ringkasan Keseluruhan")
    with hdr_right:
        AREA_CANDIDATES = ["Area", "AREA", "Wilayah", "WILAYAH", "Regional", "REGIONAL", "Area/Kanwil", "Kanwil"]

        def pick_col_any(df, candidates):
            if df is None or df.empty:
                return None
            cols_lower = {str(c).strip().lower(): c for c in df.columns}
            for cand in candidates:
                key = str(cand).strip().lower()
                if key in cols_lower:
                    return cols_lower[key]
            for c in df.columns:
                if any(k.lower() == str(c).strip().lower() for k in candidates):
                    return c
            return None

        area_col = pick_col_any(df_branch, AREA_CANDIDATES) or pick_col_any(df_employee, AREA_CANDIDATES)

        st.session_state.setdefault("selected_area", "Semua")
        if area_col:
            parts = []
            if area_col in df_branch.columns:
                parts.append(df_branch[area_col])
            if area_col in df_employee.columns:
                parts.append(df_employee[area_col])
            areas_all = pd.concat(parts, ignore_index=True) if parts else pd.Series(dtype=object)
            areas = ["Semua"] + sorted(areas_all.dropna().astype(str).str.strip().unique().tolist())
            sel_idx = areas.index(st.session_state["selected_area"]) if st.session_state["selected_area"] in areas else 0
            selected_area = st.selectbox("Area", options=areas, index=sel_idx)
            st.session_state["selected_area"] = selected_area
        else:
            selected_area = "Semua"
            st.selectbox("Area", ["(Kolom Area tidak ditemukan)"], index=0, disabled=True)

    # Filter data berdasarkan Area (jika dipilih)
    def _apply_area_filter(df):
        if df is None or df.empty:
            return df
        if not area_col or st.session_state.get("selected_area") in (None, "", "Semua"):
            return df
        return df[df[area_col].astype(str).str.strip() == st.session_state["selected_area"]]

    df_branch_view = _apply_area_filter(df_branch)
    df_employee_view = _apply_area_filter(df_employee)

    # KPI dinamis
    kpi_pimpinan = kpi_pelaksana = kpi_kriya = kpi_tad = 0
    if not df_employee_view.empty:
        # Pimpinan & Pelaksana
        if EMP_COLS.get("STATUS_PEGAWAI") in df_employee_view.columns:
            sjab = df_employee_view[EMP_COLS["STATUS_PEGAWAI"]].astype(str).str.strip().str.lower()
            kpi_pimpinan  = int(sjab.str.contains("pimpinan|kepala|manajer|manager").sum())
            kpi_pelaksana = int(sjab.str.contains("pelaksana|staf|staff|officer|analis").sum())
        elif EMP_COLS.get("POSISI") in df_employee_view.columns:
            ps = df_employee_view[EMP_COLS["POSISI"]].astype(str).str.strip().str.lower()
            kpi_pimpinan  = int(ps.str.contains("pimpinan|kepala|manajer|manager").sum())
            kpi_pelaksana = int(ps.str.contains("pelaksana|staf|staff|officer|analis").sum())

        # Kriya & TAD
        if EMP_COLS.get("STATUS_PEGAWAI") in df_employee_view.columns:
            sp = df_employee_view[EMP_COLS["STATUS_PEGAWAI"]].astype(str).str.strip().str.lower()
            kpi_kriya = int(sp.str.contains("kriya").sum())
            kpi_tad   = int(sp.str.contains(r"\btad\b|alih daya|outsourc").sum())
        elif EMP_COLS.get("POSISI") in df_employee_view.columns:
            ps = df_employee_view[EMP_COLS["POSISI"]].astype(str).str.strip().str.lower()
            kpi_kriya = int(ps.str.contains("kriya").sum())
            kpi_tad   = int(ps.str.contains(r"\btad\b|alih daya|outsourc").sum())

    total_unit = int(df_branch_view[BRANCH_UNIT_COL].astype(str).str.strip().nunique()) if not df_branch_view.empty else 0

    # Render KPI
    c1, c2, c3, c4, c5 = st.columns(5)
    with c1:
        st.markdown(
            f'<div class="kpi"><div class="title">👔 Pimpinan</div><div class="val">{kpi_pimpinan:,}</div><div class="sub">{st.session_state.get("selected_area","Semua")}</div></div>',
            unsafe_allow_html=True,
        )
    with c2:
        st.markdown(
            f'<div class="kpi purple"><div class="title">🧰 Pelaksana</div><div class="val">{kpi_pelaksana:,}</div><div class="sub">{st.session_state.get("selected_area","Semua")}</div></div>',
            unsafe_allow_html=True,
        )
    with c3:
        st.markdown(
            f'<div class="kpi"><div class="title">🧑‍🎓 Kriya Mandiri</div><div class="val">{kpi_kriya:,}</div><div class="sub">{st.session_state.get("selected_area","Semua")}</div></div>',
            unsafe_allow_html=True,
        )
    with c4:
        st.markdown(
            f'<div class="kpi green"><div class="title">🤝 TAD</div><div class="val">{kpi_tad:,}</div><div class="sub">{st.session_state.get("selected_area","Semua")}</div></div>',
            unsafe_allow_html=True,
        )
    with c5:
        st.markdown(
            f'<div class="kpi"><div class="title">🏢 Total Unit Cabang</div><div class="val">{total_unit:,}</div><div class="sub">{st.session_state.get("selected_area","Semua")}</div></div>',
            unsafe_allow_html=True,
        )

    st.markdown("<hr/>", unsafe_allow_html=True)

    # Charts (terfilter Area)
    area_label = st.session_state.get("selected_area", "Semua")
    eview = df_employee_view
    bview = df_branch_view

    colA, colB = st.columns(2)
    with colA:
        if (eview is not None) and (not eview.empty) and (EMP_COLS["GENDER"] in eview.columns):
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown(f"#### ⚧️ Distribusi Gender — {area_label}")
            g_all = eview[EMP_COLS["GENDER"]].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(g_all), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

        if (eview is not None) and (not eview.empty) and (EMP_COLS["STATUS_PEGAWAI"] in eview.columns):
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown(f"#### 🧾 Status Pegawai — {area_label}")
            s_all = eview[EMP_COLS["STATUS_PEGAWAI"]].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(s_all), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    with colB:
        if (eview is not None) and (not eview.empty) and (EMP_COLS["SOURCE"] in eview.columns):
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown(f"#### 🏭 Source Pegawai — {area_label}")
            source_all = eview[EMP_COLS["SOURCE"]].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(source_all.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

        if (eview is not None) and (not eview.empty) and (EMP_COLS["STATUS_JABATAN"] in eview.columns):
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown(f"#### 🗺️ Status Jabatan — {area_label}")
            area_all = eview[EMP_COLS["STATUS_JABATAN"]].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(area_all.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    st.markdown("<hr/>", unsafe_allow_html=True)

    # TABEL: Jumlah Pegawai per Unit (by Area)
    if (
        (eview is not None) and (not eview.empty)
        and ("_unit_norm" in eview.columns) and eview["_unit_norm"].notna().any()
        and (bview is not None) and (not bview.empty)
        and (BRANCH_UNIT_COL in bview.columns)
    ):
        emp_count = (
            eview.dropna(subset=["_unit_norm"])
                .groupby("_unit_norm").size().reset_index(name="Jumlah Pegawai")
        )

        unit_map_view = bview[[BRANCH_UNIT_COL]].copy()
        unit_map_view["_unit_norm"] = unit_map_view[BRANCH_UNIT_COL].apply(norm_txt)
        unit_map_view = unit_map_view.drop_duplicates("_unit_norm")

        merged = (
            emp_count.merge(unit_map_view, on="_unit_norm", how="inner")
                    .sort_values("Jumlah Pegawai", ascending=False)
        )

        _bad = {s.casefold() for s in PLACEHOLDERS}
        mask_valid = (
            merged[BRANCH_UNIT_COL].astype(str).str.strip().str.casefold()
            .map(lambda x: (x not in _bad) and (x != "nan"))
        )
        merged = merged[mask_valid].copy()

        if not merged.empty:
            def _btn_detail(unit_name: str) -> str:
                url = link_detail(unit_name)
                return f'<a href="{url}" target="_top" class="btn-detail">🔎 Lihat</a>'

            merged = merged.copy()
            merged["Aksi"] = merged[BRANCH_UNIT_COL].astype(str).apply(_btn_detail)
            tbl = merged[[BRANCH_UNIT_COL, "Jumlah Pegawai", "Aksi"]].reset_index(drop=True)

            st.markdown("""
            <style>
            .btn-detail{
              display:inline-block; padding:6px 12px; border-radius:10px;
              border:1px solid var(--border); text-decoration:none; color:#e5e7eb;
              background:linear-gradient(135deg,#0ea5e9 0%, #1d4ed8 60%, #0f172a 100%);
              box-shadow:0 4px 12px var(--shadow); font-weight:700;
            }
            .btn-detail:hover{ filter:brightness(1.1); }
            table.dataframe { width:100%; background:var(--panel); color:#e5e7eb;
              border:1px solid var(--border); border-collapse:collapse; }
            table.dataframe th, table.dataframe td{
              border:1px solid var(--border); padding:8px 10px; vertical-align:middle;
            }
            table.dataframe thead th{ background:rgba(148,163,184,.08); }
            </style>
            """, unsafe_allow_html=True)

            st.markdown(tbl.to_html(index=False, escape=False), unsafe_allow_html=True)
        else:
            st.info(f"Tidak ada unit valid untuk ditampilkan di Area **{area_label}**.")
    else:
        st.info(f"Tidak ada data yang cocok untuk Area **{area_label}**.")

# =========================================
# ROUTER
# =========================================
if route == "dashboard":
    page_dashboard()
elif route in ("detail", "detailb"):
    page_detail_branch()
elif route == "detaile":
    page_detail_employee()
elif route == "map":
    page_distribution_branch()
