
import streamlit as st
import pandas as pd
import re
from streamlit_folium import st_folium
import folium
import requests

st.set_page_config(page_title="BBC Branch Manager (Folium)", layout="wide")

st.title("📍 BBC Branch Manager – Peta & Detail Cabang (Klik Marker, Tanpa Cluster)")

# --- File input ---
default_path = "Mapping BBCc.xlsx"
uploaded = st.file_uploader("Unggah file Excel (opsional). Jika tidak diunggah, aplikasi memakai file default yang Anda kirim.", type=["xlsx"])
excel_path = uploaded if uploaded is not None else default_path

@st.cache_data(show_spinner=False)
def load_data(path_or_buf):
    # Sheet1: Letak Cabangnya
    # Sheet2: Cabang yang dikelola BBC (di file ini bernama "Lembar1")
    s1 = pd.read_excel(path_or_buf, sheet_name="DataBranch")
    s2 = pd.read_excel(path_or_buf, sheet_name="MappingBBC")

    # Normalize helpers
    def normalize_full(name: str) -> str:
        name = str(name).strip().upper()
        return re.sub(r"\s+", " ", name)

    # Clean s1
    s1 = s1.copy()
    s1["unit_norm"] = s1["Unit Kerja"].astype(str).apply(lambda x: re.sub(r"\s+", " ", x.strip().upper()))
    # Split lat/long
    latlon = s1["Latitude_Longitude"].astype(str).str.split(",", n=1, expand=True)
    s1["lat"] = pd.to_numeric(latlon[0], errors="coerce")
    s1["lon"] = pd.to_numeric(latlon[1], errors="coerce")

    # Melt s2 (multiple columns of UNIT KERJA / KELAS CABANG.*)
    uk_cols = [c for c in s2.columns if str(c).upper().startswith("UNIT KERJA")]
    kel_cols = [c for c in s2.columns if str(c).upper().startswith("KELAS CABANG")]

    rows = []
    for _, row in s2.iterrows():
        for i, uk_col in enumerate(uk_cols):
            uk = row.get(uk_col)
            if pd.isna(uk) or str(uk).strip() == "":
                continue
            uk_full = uk.strip().upper()
            rows.append({
                "nip": row.get("NIP") if "NIP" in s2.columns else row.get("nip"),
                "bbc": row.get("NAMA") if "NAMA" in s2.columns else row.get("nama"),
                "unit_abbrev": normalize_full(uk),
                "unit_full": uk_full,
                "kelas_cabang_bbc": row.get(kel_cols[i]) if i < len(kel_cols) else None
            })
    s2_long = pd.DataFrame(rows)

    # Join to get location and metadata
    df = s2_long.merge(s1, left_on="unit_full", right_on="unit_norm", how="left", suffixes=("_bbc", "_lokasi"))

    # Optional columns
    optional_cols = ["AREA", "Kota/Kab.", "Status Gedung", "Kelas Cabang", "Izin BI", "Unit Kerja"]
    for col in optional_cols:
        if col not in df.columns:
            df[col] = None

    # Rename for consistency in UI
    df = df.rename(columns={
        "AREA": "Area",
        "Kota/Kab.": "KotaKab",
        "Kelas Cabang": "Kelas_Cabang_Lokasi",
        "Status Gedung": "Status_Gedung",
        "Izin BI": "Izin_BI",
        "Unit Kerja": "Unit_Kerja_Lokasi"
    })

    # Derive clean cabang name
    df["Cabang"] = df["unit_full"].str.title()

    # Ensure lat/lon available
    df = df[df["lat"].notna() & df["lon"].notna()].reset_index(drop=True)

    return df

df = load_data(excel_path)

# --- Sidebar filters ---
st.sidebar.header("🔎 Filter")
areas = ["(Semua)"] + sorted([a for a in df["Area"].dropna().unique().tolist()])
area_choice = st.sidebar.selectbox("Area", areas, index=0)

bbcs = ["(Semua)"] + sorted([a for a in df["bbc"].dropna().unique().tolist()])
bbc_choice = st.sidebar.selectbox("BBC (Nama)", bbcs, index=0)

# Cabang choices depend on filters
filtered = df.copy()
if area_choice != "(Semua)":
    filtered = filtered[filtered["Area"] == area_choice]
if bbc_choice != "(Semua)":
    filtered = filtered[filtered["bbc"] == bbc_choice]

cabangs = ["(Semua)"] + sorted(filtered["Cabang"].unique().tolist())
cabang_choice = st.sidebar.selectbox("Cabang", cabangs, index=0)

if cabang_choice != "(Semua)":
    filtered = filtered[filtered["Cabang"] == cabang_choice]

st.sidebar.markdown("---")
st.sidebar.write(f"📦 Data tersaring: **{len(filtered)}** cabang")

if len(filtered) == 0:
    st.warning("Tidak ada data sesuai filter.")
    st.stop()

# --- Color strategy ---
# If Area == (Semua) => color by Area
# Else (Area selected) => color by BBC
if area_choice == "(Semua)":
    color_key_series = filtered["Area"].fillna("Tanpa Area")
    legend_title = "Warna per Area"
else:
    color_key_series = filtered["bbc"].fillna("Tanpa BBC")
    legend_title = f"Warna per BBC (Area: {area_choice})"

# Folium marker color palette (limited set)
AVAILABLE_COLORS = [
    "red","blue","green","purple","orange","darkred","lightred","beige",
    "darkblue","darkgreen","cadetblue","darkpurple","white","pink",
    "lightblue","lightgreen","gray","black","lightgray"
]
unique_keys = list(dict.fromkeys(color_key_series.tolist()))  # preserve order
palette = {k: AVAILABLE_COLORS[i % len(AVAILABLE_COLORS)] for i, k in enumerate(unique_keys)}

# --- Map (Folium, NO cluster) ---
center_lat = float(filtered["lat"].mean())
center_lon = float(filtered["lon"].mean())

m = folium.Map(location=[center_lat, center_lon], zoom_start=7, tiles="OpenStreetMap")  # OSM tiles

for _, r in filtered.iterrows():
    key = (r["Area"] if area_choice == "(Semua)" else r["bbc"]) or "N/A"
    color = palette.get(key, "blue")

    html = f"""
    <div style='font-size:14px'>
        <b>{r['Cabang']}</b><br/>
        BBC: {r.get('bbc') or '—'}<br/>
        Area: {r.get('Area') or '—'}<br/>
        Kota/Kab: {r.get('KotaKab') or '—'}<br/>
        Kelas (Lokasi): {r.get('Kelas_Cabang_Lokasi') or '—'}<br/>
        Kelas (BBC): {r.get('kelas_cabang_bbc') or '—'}<br/>
        Status Gedung: {r.get('Status_Gedung') or '—'}<br/>
        Izin BI: {r.get('Izin_BI') or '—'}<br/>
        Koordinat: {r['lat']}, {r['lon']}
    </div>
    """
    folium.Marker(
        location=[r["lat"], r["lon"]],
        popup=folium.Popup(html, max_width=350),
        tooltip=r["Cabang"],
        icon=folium.Icon(color=color)
    ).add_to(m)

# --- Add legend ---
legend_items = "".join([
    f"<div style='display:flex;align-items:center;margin-bottom:4px'><span style='display:inline-block;width:12px;height:12px;background:{c};margin-right:6px;border:1px solid #333'></span>{k}</div>"
    for k, c in palette.items()
])
legend_html = f"""
<div style='position: fixed; 
     bottom: 20px; left: 20px; z-index: 9999; 
     background: rgba(0,0,0,0.65); padding: 10px 12px; 
     border-radius: 6px; color: white; font-size: 13px'>
  <div style='font-weight:600;margin-bottom:6px'>{legend_title}</div>
  {''.join([
      f"<div style='display:flex;align-items:center;margin-bottom:4px'>"
      f"<span style='display:inline-block;width:12px;height:12px;background:{c};"
      f"margin-right:6px;border:1px solid #fff'></span>{k}</div>"
      for k, c in palette.items()
  ])}
</div>
"""

m.get_root().html.add_child(folium.Element(legend_html))

# --- Optional: OSRM routing (unchanged) ---
with st.expander("🧭 Rute OSRM (opsional)"):
    st.caption("Hitung rute berkendara antara dua cabang menggunakan OSRM demo server.")
    c1, c2, c3 = st.columns([3,3,1])
    all_cabangs = filtered["Cabang"].unique().tolist()
    origin = c1.selectbox("Asal", all_cabangs, index=0 if all_cabangs else None)
    dest = c2.selectbox("Tujuan", all_cabangs, index=1 if len(all_cabangs)>1 else 0)
    do_route = c3.button("Hitung Rute")
    if do_route and origin and dest and origin != dest:
        o = filtered.loc[filtered["Cabang"] == origin].iloc[0]
        d = filtered.loc[filtered["Cabang"] == dest].iloc[0]
        url = f"https://router.project-osrm.org/route/v1/driving/{o['lon']},{o['lat']};{d['lon']},{d['lat']}?overview=full&geometries=geojson"
        try:
            resp = requests.get(url, timeout=10)
            data = resp.json()
            if "routes" in data and len(data["routes"]) > 0:
                route = data["routes"][0]
                coords = route["geometry"]["coordinates"]  # [lon, lat]
                polyline = [(lat, lon) for lon, lat in coords]
                folium.PolyLine(polyline, weight=5, opacity=0.8).add_to(m)
                st.success(f"Perkiraan jarak: {route.get('distance',0)/1000:.2f} km, durasi: {route.get('duration',0)/60:.1f} menit.")
            else:
                st.warning("Tidak mendapatkan rute dari OSRM.")
        except Exception as e:
            st.error(f"Gagal mengambil rute dari OSRM: {e}")

st_data = st_folium(m, width=None)

# --- List & details ---
st.subheader("📄 Daftar Cabang")
for idx, row in filtered.sort_values(["Area", "bbc", "Cabang"]).iterrows():
    with st.container(border=True):
        cols = st.columns([3,2,2,2])
        cols[0].markdown(f"**{row['Cabang']}**\n\nArea: {row.get('Area') or '—'}\n\nKota/Kab: {row.get('KotaKab') or '—'}")
        cols[1].markdown(f"**BBC**\n{row.get('bbc') or '—'}")
        cols[2].markdown(f"**Kelas (Lokasi)**\n{row.get('Kelas_Cabang_Lokasi') or '—'}")
        cols[3].markdown(f"**Kelas (BBC)**\n{row.get('kelas_cabang_bbc') or '—'}")
