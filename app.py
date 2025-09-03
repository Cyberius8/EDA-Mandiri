# app.py
import streamlit as st
import pandas as pd
import numpy as np
import re
import sqlite3
from urllib.parse import quote_plus

import folium
from folium.plugins import MarkerCluster
from streamlit_folium import st_folium

import plotly.express as px
import plotly.graph_objects as go
import os
from datetime import datetime
from math import atan2, radians, degrees, sin, cos
import requests

from urllib.parse import quote_plus

# =========================================
# PAGE SETUP
# =========================================
st.set_page_config(page_title="📍 Persebaran Lokasi Bank", layout="wide")
st.title("📍 Persebaran Lokasi Bank")

# =========================================
# STYLE (kartu + tema gelap + gradasi)
# =========================================
st.markdown("""
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
</style>
""", unsafe_allow_html=True)

# =========================================
# HELPERS
# =========================================
DB_PATH = "bank_dashboard.db"
PLACEHOLDERS = {"-", "–", "—", "N/A", "NA", "n/a", "na", "", "None", "null", "Null"}
ALLOWED_ROUTES = {"dashboard", "detail", "map", "update"}

def _read_qp():
    # aman untuk Streamlit versi baru & lama
    try:
        qp = dict(st.query_params)
    except Exception:
        try:
            qp = st.experimental_get_query_params()
        except Exception:
            qp = {}
    # flattener
    flat = {}
    for k,v in qp.items():
        flat[k] = v[0] if isinstance(v, list) and v else v
    return flat

def get_route():
    qp = _read_qp()
    route = (qp.get("route") or "dashboard").lower()
    if route not in ALLOWED_ROUTES:
        route = "dashboard"
    return route, qp

def goto(route: str, **params):
    assert route in ALLOWED_ROUTES
    qp = {"route": route}
    qp.update({k: v for k, v in params.items() if v not in (None, "")})
    try:
        st.query_params.clear()
        st.query_params.update(qp)    # Streamlit baru
    except Exception:
        st.experimental_set_query_params(**qp)  # fallback lama
    st.rerun()

    
# ------- ROUTER STATE -------
if "selected_unit" not in st.session_state:
    st.session_state["selected_unit"] = None

route, qp = get_route()
if qp.get("unit"):
    st.session_state["selected_unit"] = qp["unit"]

# ------- HEADERBAR -------
hb = st.container()
with hb:
    c1, c2, c3, c4 = st.columns([1,1,1,1])
    if c1.button("Dashboard", use_container_width=True):
        goto("dashboard")
    if c2.button("Distribution Branch", use_container_width=True):
        goto("map")
    if c3.button("Detail Branch", use_container_width=True):
        goto("detail", unit=st.session_state.get("selected_unit"))
    if c4.button("Update Data", use_container_width=True):
        goto("update")

def _fmt_time(sec: float) -> str:
    sec = int(sec)
    h, m = sec // 3600, (sec % 3600) // 60
    return f"{h} j {m} m" if h else f"{m} m"

def _initial_bearing(lat1, lon1, lat2, lon2) -> float:
    φ1, φ2 = radians(lat1), radians(lat2)
    Δλ = radians(lon2 - lon1)
    x = sin(Δλ) * cos(φ2)
    y = cos(φ1)*sin(φ2) - sin(φ1)*cos(φ2)*cos(Δλ)
    return (degrees(atan2(x, y)) + 360) % 360  # 0°=Utara, 90°=Timur

def _osrm_route(lat1, lon1, lat2, lon2, alternatives=True):
    """
    Mengambil rute 'driving' dari OSRM (OSM). Return list of routes.
    """
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


def clean_dataframe_for_arrow(df: pd.DataFrame) -> pd.DataFrame:
    df = df.copy()
    for col in df.columns:
        if pd.api.types.is_object_dtype(df[col]) or pd.api.types.is_string_dtype(df[col]):
            df[col] = df[col].apply(lambda x: np.nan if (pd.isna(x) or str(x).strip() in PLACEHOLDERS) else x)

    for col in df.columns:
        s = df[col]
        if pd.api.types.is_object_dtype(s) or pd.api.types.is_string_dtype(s):
            s_num = pd.to_numeric(s, errors="coerce")
            if s_num.notna().mean() >= 0.6:
                if (s_num.dropna() % 1 == 0).all():
                    df[col] = s_num.round(0).astype("Int64")
                else:
                    df[col] = s_num.astype(float)
            else:
                df[col] = s.astype(str)
        elif pd.api.types.is_categorical_dtype(s):
            df[col] = s.astype(str)
    return df

def norm_txt(x):
    if pd.isna(x): return None
    return str(x).strip().casefold()

def extract_unit_from_popup(popup_html: str):
    m = re.search(r'data-unit="([^"]+)"', str(popup_html))
    return m.group(1).strip() if m else None

# plotly helpers (tema gelap + gradasi)
def bar_grad(series, title="", orientation="v"):
    data = series.reset_index()
    data.columns = ["Kategori", "Jumlah"]
    if orientation == "v":
        fig = px.bar(
            data, x="Kategori", y="Jumlah",
            color="Jumlah", color_continuous_scale="Blues",
            text="Jumlah"
        )
        fig.update_traces(textposition="outside")
    else:
        fig = px.bar(
            data, x="Jumlah", y="Kategori", orientation="h",
            color="Jumlah", color_continuous_scale="Blues",
            text="Jumlah"
        )
        fig.update_traces(textposition="outside")
    fig.update_layout(
        title=title,
        height=360,
        margin=dict(l=10, r=10, t=40, b=10),
        coloraxis_showscale=False,
        plot_bgcolor="#0b1220",
        paper_bgcolor="#0b1220",
        font_color="#e5e7eb"
    )
    return fig

def donut(series, title=""):
    data = series.reset_index()
    data.columns = ["Kategori", "Jumlah"]
    fig = px.pie(
        data, values="Jumlah", names="Kategori", hole=0.55,
        color="Kategori",
        color_discrete_sequence=px.colors.sequential.Blues_r
    )
    fig.update_layout(
        title=title,
        height=360,
        margin=dict(l=10, r=10, t=40, b=10),
        plot_bgcolor="#0b1220",
        paper_bgcolor="#0b1220",
        font_color="#e5e7eb",
        legend=dict(orientation="h", y=-0.15)
    )
    return fig

def spider_chart_from_counts(counts: pd.Series, title: str = "Spider Chart — Sumber Pegawai") -> go.Figure:
    counts = counts.dropna()
    counts = counts[counts.index.astype(str).str.strip() != ""]
    counts = counts.sort_values(ascending=False)

    labels = counts.index.astype(str).tolist()
    values = counts.values.tolist()
    labels += labels[:1]
    values += values[:1]
    max_r = max(values) if values else 1

    fig = go.Figure()
    fig.add_trace(go.Scatterpolar(
        r=values,
        theta=labels,
        fill='toself',
        mode='lines+markers',
        name='Jumlah'
    ))
    fig.update_layout(
        title=title,
        showlegend=False,
        margin=dict(l=20, r=20, t=50, b=20),
        polar=dict(
            radialaxis=dict(visible=True, range=[0, max_r * 1.1]),
            angularaxis=dict(direction='clockwise')
        )
    )
    return fig

# =========================================
# DB I/O
# =========================================
def init_db():
    with sqlite3.connect(DB_PATH) as conn:
        conn.execute("""
        CREATE TABLE IF NOT EXISTS branches (
            id INTEGER PRIMARY KEY AUTOINCREMENT
        );
        """)
        conn.execute("""
        CREATE TABLE IF NOT EXISTS employees (
            id INTEGER PRIMARY KEY AUTOINCREMENT
        );
        """)
    # NOTE: Skema fleksibel → kita pakai replace_table helper di bawah

def replace_table(df: pd.DataFrame, table_name: str):
    with sqlite3.connect(DB_PATH) as conn:
        df.to_sql(table_name, conn, if_exists="replace", index=False)

def read_table(table_name: str) -> pd.DataFrame:
    if not os.path.exists(DB_PATH):
        return pd.DataFrame()
    with sqlite3.connect(DB_PATH) as conn:
        try:
            return pd.read_sql(f"SELECT * FROM {table_name}", conn)
        except Exception:
            return pd.DataFrame()
        
def link_detail(unit_name: str) -> str:
    return f'/?route=detail&unit={quote_plus(unit_name or "")}'


# =========================================
# STATE
# =========================================
route, qp = get_route()
if qp.get("unit"):
    st.session_state["selected_unit"] = qp["unit"]

# =========================================
# LOAD DATA (prefer DB; else wait upload di Update Data)
# =========================================
df_branch = read_table("branches")
df_employee = read_table("employees")

# Nama kolom unit yang mungkin
def pick_branch_unit_col(df_branch: pd.DataFrame):
    for c in ["Unit Kerja", "Kantor", "Nama Cabang", "Nama Unit"]:
        if c in df_branch.columns:
            return c
    return None

BRANCH_UNIT_COL = pick_branch_unit_col(df_branch)

def ensure_parsed_latlon(df_branch: pd.DataFrame) -> pd.DataFrame:
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

if not df_branch.empty:
    df_branch = ensure_parsed_latlon(df_branch)

# Helper normalisasi unit di employees
def attach_unit_norm(df_b: pd.DataFrame, df_e: pd.DataFrame):
    bcol = pick_branch_unit_col(df_b)
    if bcol:
        df_b = df_b.copy()
        df_b["_unit_norm"] = df_b[bcol].apply(norm_txt)

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

if not df_branch.empty or not df_employee.empty:
    df_branch, df_employee, BRANCH_UNIT_COL = attach_unit_norm(df_branch, df_employee)

# =========================================
# PAGES
# =========================================

def page_update_data():
    st.subheader("⬆️ Update Data")
    st.markdown("Upload dua file berikut, lalu tekan **Simpan ke Database**:")
    c1, c2 = st.columns(2)
    with c1:
        up_branch = st.file_uploader("📄 File Branch (Branch Map R11.xlsx)", type=["xlsx"], key="up_branch")
    with c2:
        up_employee = st.file_uploader("👥 File Pegawai (Data Pegawai.xlsx)", type=["xlsx"], key="up_employee")

    if st.button("💾 Simpan ke Database", type="primary", use_container_width=True):
        if not up_branch or not up_employee:
            st.error("Mohon upload **kedua** file terlebih dahulu.")
            return

        # Baca & bersihkan
        try:
            df_b_raw = pd.read_excel(up_branch)
            df_e_raw = pd.read_excel(up_employee)
        except Exception as e:
            st.error(f"Gagal membaca Excel: {e}")
            return

        df_b = clean_dataframe_for_arrow(df_b_raw)
        df_e = clean_dataframe_for_arrow(df_e_raw)

        # Validasi latlon
        df_b = ensure_parsed_latlon(df_b)
        if df_b.empty or "Latitude" not in df_b.columns or "Longitude" not in df_b.columns:
            st.error("Kolom koordinat tidak valid. Pastikan ada 'Latitude_Longitude' (format 'lat,lon') atau kolom 'Latitude' & 'Longitude'.")
            return

        # Simpan
        init_db()
        try:
            replace_table(df_b, "branches")
            replace_table(df_e, "employees")
        except Exception as e:
            st.error(f"Gagal menyimpan ke database: {e}")
            return

        st.success("✅ Data berhasil disimpan ke database `bank_dashboard.db`.")
        st.session_state["selected_unit"] = None
        goto("map")  # atau "dashboard"

def page_distribution_branch():
    if df_branch.empty:
        st.info("Tidak ada data cabang di database. Buka tab **Update Data** untuk mengunggah.")
        return

    # --- Session state utk start/end ---
    st.session_state.setdefault("start_unit", None)
    st.session_state.setdefault("end_unit", None)

    st.subheader("🗺️ Distribution Branch — Peta Interaktif")

    # ── Panel kontrol pemilihan titik ──
    bcol = BRANCH_UNIT_COL or "Unit/Kantor"
    units = (
        df_branch[bcol]
        .dropna()
        .astype(str)
        .drop_duplicates()
        .tolist()
    )
    opt0 = "— pilih —"

    c1, c2= st.columns([1,1])
    with c1:
        idx = 0
        if st.session_state["start_unit"] in units:
            idx = units.index(st.session_state["start_unit"]) + 1
        start_sel = st.selectbox("Titik awal", [opt0] + units, index=idx, key="sb_start_sel")
    with c2:
        idx = 0
        if st.session_state["end_unit"] in units:
            idx = units.index(st.session_state["end_unit"]) + 1
        end_sel = st.selectbox("Tujuan", [opt0] + units, index=idx, key="sb_end_sel")

    if start_sel != opt0:
        st.session_state["start_unit"] = start_sel
    if end_sel != opt0:
        st.session_state["end_unit"] = end_sel

    # --- Utility ambil row by unit ---
    def _row_by_unit(u):
        if not u:
            return None
        _r = df_branch[df_branch[bcol].astype(str) == str(u)]
        return None if _r.empty else _r.iloc[0]

    row_start = _row_by_unit(st.session_state["start_unit"])
    row_end   = _row_by_unit(st.session_state["end_unit"])

    # --- Peta dasar + marker cluster ---
    center_lat = float(df_branch["Latitude"].mean())
    center_lon = float(df_branch["Longitude"].mean())
    fmap = folium.Map(location=[center_lat, center_lon], zoom_start=7, tiles="CartoDB positron")
    cluster = MarkerCluster().add_to(fmap)

    for _, row in df_branch.iterrows():
        kode = str(row.get("Kode Cabang", "") or "")
        unit = str(row.get(bcol, "") or "")
        nama_kantor = str(
            row.get("Nama Kantor")
            or row.get("Kantor")
            or row.get("Nama Cabang")
            or row.get(bcol)
            or kode
            or "—"
        )
        lat = float(row["Latitude"]); lon = float(row["Longitude"])

        BASE_PREFIX = st.get_option("server.baseUrlPath") or ""
        BASE_PREFIX = "" if BASE_PREFIX in ("", "/") else f"/{BASE_PREFIX.strip('/')}"
        gmaps_url = f"https://www.google.com/maps/search/?api=1&query={lat},{lon}"
        detail_url = f"{BASE_PREFIX}/?route=detail&unit={quote_plus(unit)}"

        popup_html = f"""
        <div data-unit="{unit}">
        <b>🏢 {nama_kantor}</b><br>
        <a href="{gmaps_url}" target="_blank" rel="noopener">📍 Buka di Google Maps</a><br>
        <a href="{detail_url}" target="_blank" rel="noopener">🔎 Lihat Detail Branch</a><br>
        </div>
        """

        # Warna khusus utk start/end
        if row_start is not None and str(unit) == str(row_start[bcol]):
            icon = folium.Icon(color="green", icon="play", prefix="fa")
        elif row_end is not None and str(unit) == str(row_end[bcol]):
            icon = folium.Icon(color="red", icon="flag-checkered", prefix="fa")
        else:
            icon = folium.Icon(color="blue", icon="building", prefix="fa")

        folium.Marker(
            location=[lat, lon],
            popup=folium.Popup(popup_html, max_width=320),
            tooltip=f"🏢 {nama_kantor}",
            icon=icon
        ).add_to(cluster)

    # --- (Opsional) gambar rute bila start & end sudah ada ---
    routes_info = []
    if (row_start is not None) and (row_end is not None):
        lat1, lon1 = float(row_start["Latitude"]), float(row_start["Longitude"])
        lat2, lon2 = float(row_end["Latitude"]), float(row_end["Longitude"])

        try:
            routes = _osrm_route(lat1, lon1, lat2, lon2, alternatives=True)
            for i, r in enumerate(routes, 1):
                dist_km = r["distance"] / 1000.0
                dur_s = r["duration"]
                coords = r["geometry"]["coordinates"]  # [lon, lat]
                latlon = [(c[1], c[0]) for c in coords]
                folium.PolyLine(
                    latlon, weight=6, opacity=0.9,
                    tooltip=f"Rute {i}: {dist_km:.1f} km, {_fmt_time(dur_s)}"
                ).add_to(fmap)
                routes_info.append((i, dist_km, dur_s, r))
        except Exception as e:
            st.error(f"Gagal ambil rute dari OSRM: {e}")

    # --- Render peta & tangkap klik ---
    st_data = st_folium(fmap, width=1800, height=540)

    # Jika popup terakhir diklik, set sesuai mode
    if st_data and st_data.get("last_object_clicked_popup"):
        picked = extract_unit_from_popup(st_data["last_object_clicked_popup"])
        if picked:
            if click_mode == "Start":
                st.session_state["start_unit"] = picked.strip()
            else:
                st.session_state["end_unit"] = picked.strip()

    # --- Ringkasan rute di bawah peta ---
    if routes_info:
        lat1, lon1 = float(row_start["Latitude"]), float(row_start["Longitude"])
        lat2, lon2 = float(row_end["Latitude"]), float(row_end["Longitude"])
        bearing = _initial_bearing(lat1, lon1, lat2, lon2)
        st.success(
            f"**{row_start[bcol]} → {row_end[bcol]}** • "
            f"Arah awal (bearing): {bearing:.0f}° — "
            f"Tersedia {len(routes_info)} alternatif rute."
        )
        for i, dist_km, dur_s, r in routes_info:
            with st.expander(f"Rute {i}: {dist_km:.1f} km • {_fmt_time(dur_s)} (klik untuk lihat langkah)"):
                steps = []
                for leg in r["legs"]:
                    for s in leg["steps"]:
                        # OSRM publik kadang tidak punya teks instruksi yang enak dibaca
                        txt = s.get("maneuver", {}).get("instruction", "") or s.get("name","")
                        if txt:
                            steps.append(txt)
                if steps:
                    for idx, tx in enumerate(steps, 1):
                        st.write(f"{idx}. {tx}")
                else:
                    st.caption("Instruksi teks detail tidak tersedia dari OSRM publik. "
                               "Jika perlu turn-by-turn berbahasa, pakailah OpenRouteService/Google.")
    else:
        # Info pilihan yang aktif
        sel_start = st.session_state.get("start_unit")
        sel_end   = st.session_state.get("end_unit")
        if sel_start or sel_end:
            st.info(f"Start: **{sel_start or '—'}** • Tujuan: **{sel_end or '—'}** — pilih keduanya untuk menampilkan rute.")

    

def page_detail_branch():
    if df_branch.empty or df_employee.empty:
        st.info("Data belum tersedia. Silakan unggah di tab **Update Data**.")
        return

    # Kumpulan unit untuk dropdown
    all_units = (
        df_branch[BRANCH_UNIT_COL]
        .dropna()
        .astype(str)
        .map(str.strip)
        .unique()
        .tolist()
    )
    all_units = sorted([u for u in all_units if u])

    # Header filter
    st.subheader("📌 Detail Pegawai – Unit Dipilih")
    option_placeholder = "— (pilih dari klik peta) —"
    option_all = "Semua"
    manual_unit = st.selectbox(
        "🏢 Pilih Unit",
        options=[option_placeholder] + all_units,
        index=0
    )

    # Prioritas: manual > state dari peta
    selected_unit_display = st.session_state.get("selected_unit")

    if manual_unit != option_placeholder:
        if manual_unit != selected_unit_display:
            st.session_state["selected_unit"] = manual_unit
            goto("detail", unit=manual_unit)  # update URL + rerun
        else:
            selected_unit_display = manual_unit


    if not selected_unit_display:
        st.info("Pilih unit dari dropdown atau klik marker di tab **Distribution Branch**.")
        return

    st.markdown(f"### 🏦 {selected_unit_display}")

    unit_norm = norm_txt(selected_unit_display)
    df_filtered = df_employee[df_employee["_unit_norm"] == unit_norm].copy()

    # Filter baris kedua (Gender, Status, Agama, Generasi)
    k1, k2, k3 = st.columns(3)
    with k1:
        st.markdown(
            f'<div class="kpi"><div class="title">👥 Total Pegawai (unit)</div>'
            f'<div class="val">{len(df_filtered):,}</div>'
            f'<div class="sub">Unit: {selected_unit_display}</div></div>',
            unsafe_allow_html=True
        )
    with k2:
        g_opts = sorted(df_employee.get("Gender", pd.Series(dtype=str)).dropna().astype(str).unique().tolist())
        s_opts = sorted(df_employee.get("Status Pegawai", pd.Series(dtype=str)).dropna().astype(str).unique().tolist())
        sel_gender = st.multiselect("⚧️ Gender", g_opts, default=g_opts) if g_opts else []
        sel_status = st.multiselect("🧾 Status Pegawai", s_opts, default=s_opts) if s_opts else []
    with k3:
        a_opts = sorted(df_employee.get("Agama", pd.Series(dtype=str)).dropna().astype(str).unique().tolist())
        gen_opts = sorted(df_employee.get("Generasi", pd.Series(dtype=str)).dropna().astype(str).unique().tolist())
        sel_agama = st.multiselect("🛐 Agama", a_opts, default=a_opts) if a_opts else []
        sel_generasi = st.multiselect("👶 Generasi", gen_opts, default=gen_opts) if gen_opts else []

    # Terapkan filter ke df_filtered
    if sel_gender:
        df_filtered = df_filtered[df_filtered.get("Gender", "").isin(sel_gender)]
    if sel_status:
        df_filtered = df_filtered[df_filtered.get("Status Pegawai", "").isin(sel_status)]
    if sel_agama:
        df_filtered = df_filtered[df_filtered.get("Agama", "").isin(sel_agama)]
    if sel_generasi:
        df_filtered = df_filtered[df_filtered.get("Generasi", "").isin(sel_generasi)]

    if df_filtered.empty:
        st.warning("❌ Belum ada data pegawai untuk unit ini (atau tidak lolos filter).")
        return

    # KPI gender mini
    gcount = df_filtered.get("Gender", pd.Series([], dtype=str)).astype(str).str.strip().value_counts()
    male = int(gcount.get("Male", 0) + gcount.get("Laki-laki", 0))
    female = int(gcount.get("Female", 0) + gcount.get("Perempuan", 0))
    mcol1, mcol2, mcol3 = st.columns(3)
    mcol1.metric("Total Pegawai", len(df_filtered))
    mcol2.metric("Laki-laki 👨", male)
    mcol3.metric("Perempuan 👩", female)

    # Tabel ringkas
    cols_show = [c for c in ["NIP", "Nama", "Posisi", "Gender", "Status Pegawai"] if c in df_filtered.columns]
    st.dataframe(df_filtered[cols_show] if cols_show else df_filtered, use_container_width=True)

    # Visual
    csou, cband = st.columns(2)
    with csou:
        if "Source Pegawai" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🕸️ Spider Chart — Source Pegawai")
            source_count = (
                df_filtered["Source Pegawai"]
                .astype(str).str.strip()
                .replace({"nan": None, "NaN": None, "": None})
                .dropna()
                .value_counts()
            )
            st.plotly_chart(spider_chart_from_counts(source_count), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    with cband:
        if "Band" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🏷️ Band Pegawai")
            band_count = df_filtered["Band"].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(band_count.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    cpos, cstat = st.columns(2)
    with cpos:
        if "Generasi" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 👶 Generasi")
            gen_count = df_filtered["Generasi"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(gen_count), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
    with cstat:
        if "Status Pegawai" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🧾 Status Pegawai")
            status_count = df_filtered["Status Pegawai"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(status_count), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    cgen, cagama = st.columns(2)
    with cgen:
        if "Gender" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### ⚧️ Gender")
            gcount2 = df_filtered["Gender"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(gcount2), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
    with cagama:
        if "Agama" in df_filtered.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🛐 Agama")
            agama_count = df_filtered["Agama"].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(agama_count.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

def page_dashboard():
    if df_branch.empty or df_employee.empty or BRANCH_UNIT_COL is None:
        st.info("Data belum lengkap. Silakan isi lewat tab **Update Data**.")
        return

    st.subheader("📊 Dashboard — Ringkasan Keseluruhan")

    # KPI contoh (silakan sambungkan dengan kolom asli bila tersedia)
    kpi_pimpinan, kpi_pelaksana, kpi_kriya, kpi_tad = 428, 737, 243, 1024
    c1, c2, c3, c4, c5 = st.columns(5)
    with c1:
        st.markdown(f'<div class="kpi"><div class="title">👔 Pimpinan</div><div class="val">{kpi_pimpinan:,}</div><div class="sub">Total</div></div>', unsafe_allow_html=True)
    with c2:
        st.markdown(f'<div class="kpi purple"><div class="title">🧰 Pelaksana</div><div class="val">{kpi_pelaksana:,}</div><div class="sub">Total</div></div>', unsafe_allow_html=True)
    with c3:
        st.markdown(f'<div class="kpi"><div class="title">🧑‍🎓 Kriya Mandiri</div><div class="val">{kpi_kriya:,}</div><div class="sub">Total</div></div>', unsafe_allow_html=True)
    with c4:
        st.markdown(f'<div class="kpi green"><div class="title">🤝 TAD</div><div class="val">{kpi_tad:,}</div><div class="sub">Total</div></div>', unsafe_allow_html=True)
    with c5:
        st.markdown(f'<div class="kpi"><div class="title">🏢 Total Unit Cabang</div><div class="val">{df_branch[BRANCH_UNIT_COL].nunique():,}</div><div class="sub">Dari database</div></div>', unsafe_allow_html=True)

    st.markdown("<hr/>", unsafe_allow_html=True)

    # Beberapa ringkasan seluruh data
    colA, colB = st.columns(2)
    with colA:
        if "Gender" in df_employee.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### ⚧️ Distribusi Gender (All)")
            g_all = df_employee["Gender"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(g_all), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
        if "Status Pegawai" in df_employee.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🧾 Status Pegawai (All)")
            s_all = df_employee["Status Pegawai"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(s_all), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    with colB:
        if "Agama" in df_employee.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 🛐 Agama (All)")
            agama_all = df_employee["Agama"].astype(str).str.strip().value_counts()
            st.plotly_chart(bar_grad(agama_all.sort_values(), orientation="h"), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)
        if "Generasi" in df_employee.columns:
            st.markdown('<div class="card">', unsafe_allow_html=True)
            st.markdown("#### 👶 Generasi (All)")
            gen_all = df_employee["Generasi"].astype(str).str.strip().value_counts()
            st.plotly_chart(donut(gen_all), use_container_width=True)
            st.markdown('</div>', unsafe_allow_html=True)

    st.markdown("<hr/>", unsafe_allow_html=True)

    # Tabel jumlah pegawai per unit
    if df_employee["_unit_norm"].notna().any() and BRANCH_UNIT_COL in df_branch.columns:
        # join kasar: count per _unit_norm dan map ke nama unit dari branch (normalize juga)
        unit_map = df_branch[[BRANCH_UNIT_COL]].copy()
        unit_map["_unit_norm"] = unit_map[BRANCH_UNIT_COL].apply(norm_txt)
        emp_count = (df_employee.dropna(subset=["_unit_norm"])
                     .groupby("_unit_norm").size().reset_index(name="Jumlah Pegawai"))
        merged = emp_count.merge(unit_map.drop_duplicates("_unit_norm"), on="_unit_norm", how="left")
        merged = merged.sort_values("Jumlah Pegawai", ascending=False)
        # Di page_dashboard(), setelah membuat 'merged'
        merged["Detail"] = merged[BRANCH_UNIT_COL].astype(str).apply(
            lambda u: f"[Lihat]({link_detail(u)})"
        )
        st.markdown("#### 📋 Jumlah Pegawai per Unit")
        st.dataframe(merged[[BRANCH_UNIT_COL, "Jumlah Pegawai", "Detail"]], use_container_width=True)

# =========================================
# ROUTER
# =========================================
if route == "dashboard":
    page_dashboard()
elif route == "detail":
    page_detail_branch()
elif route == "map":
    page_distribution_branch()
elif route == "update":
    page_update_data()

