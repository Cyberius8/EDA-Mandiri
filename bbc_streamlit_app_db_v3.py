
import streamlit as st
import pandas as pd
import sqlite3
from streamlit_folium import st_folium
import folium
import urllib.parse

st.set_page_config(page_title="BBC Branch Manager", layout="wide")
st.title("📍 BBC Branch Manager – Peta & Detail Cabang")

DB_PATH = "bbc_branches.db"

# ---------- Helpers ----------
EMOJI_BY_COLOR = {
    "red": "🟥", "blue": "🟦", "green": "🟩", "purple": "🟪", "orange": "🟧",
    "darkred": "🟥", "lightred": "🟥", "beige": "🟫",
    "darkblue": "🟦", "darkgreen": "🟩", "cadetblue": "🟦", "darkpurple": "🟪",
    "white": "⬜", "pink": "🩷",
    "lightblue": "🟦", "lightgreen": "🟩", "gray": "⬜", "black": "⬛", "lightgray": "⬜"
}

def color_emoji(color: str) -> str:
    return EMOJI_BY_COLOR.get(color, "🔘")

@st.cache_data(show_spinner=False)
def load_from_db(db_path: str) -> pd.DataFrame:
    con = sqlite3.connect(db_path)
    df = pd.read_sql_query("SELECT * FROM branches_joined", con)
    con.close()
    # Derive clean cabang name
    df["Cabang"] = df["unit_full"].str.title()
    return df

df = load_from_db(DB_PATH)

# ---------- Sidebar filters ----------
st.sidebar.header("🔎 Filter")
areas = ["(Semua)"] + sorted([a for a in df["Area"].dropna().unique().tolist()])
area_choice = st.sidebar.selectbox("Area", areas, index=0)

bbcs = ["(Semua)"] + sorted([a for a in df["bbc"].dropna().unique().tolist()])
bbc_choice = st.sidebar.selectbox("BBC (Nama)", bbcs, index=0)

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

# ---------- Coloring logic ----------
if area_choice == "(Semua)":
    color_key_series = filtered["Area"].fillna("Tanpa Area")
    legend_title = "Warna per Area"
else:
    color_key_series = filtered["bbc"].fillna("Tanpa BBC")
    legend_title = f"Warna per BBC (Area: {area_choice})"

AVAILABLE_COLORS = [
    "red","blue","green","purple","orange","darkred","lightred","beige",
    "darkblue","darkgreen","cadetblue","darkpurple","white","pink",
    "lightblue","lightgreen","gray","black","lightgray"
]
unique_keys = list(dict.fromkeys(color_key_series.tolist()))
palette = {k: AVAILABLE_COLORS[i % len(AVAILABLE_COLORS)] for i, k in enumerate(unique_keys)}

# ---------- Map ----------
center_lat = float(filtered["lat"].mean())
center_lon = float(filtered["lon"].mean())
m = folium.Map(location=[center_lat, center_lon], zoom_start=7, tiles="OpenStreetMap")

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

# Legend (dark transparent background, white text)
if area_choice == "(Semua)":
    # legend by Area
    counts = filtered.groupby("Area")["Cabang"].nunique().to_dict()
else:
    counts = filtered.groupby("bbc")["Cabang"].nunique().to_dict()

legend_items = "".join([
    f"<div style='display:flex;align-items:center;margin-bottom:4px'>"
    f"<span style='display:inline-block;width:12px;height:12px;background:{palette[k]};"
    f"margin-right:6px;border:1px solid #fff'></span>"
    f"{k} ({counts.get(k,0)})</div>"
    for k in palette.keys()
])
legend_html = f"""
<div style='position: fixed; bottom: 20px; left: 20px; z-index: 9999; 
     background: rgba(0,0,0,0.65); padding: 10px 12px; 
     border-radius: 6px; color: white; font-size: 13px'>
  <div style='font-weight:600;margin-bottom:6px'>{legend_title}</div>
  {legend_items}
</div>
"""
m.get_root().html.add_child(folium.Element(legend_html))

st_folium(m, width=None)

# ---------- Interaktif BBC Panel (tanpa mengubah filter peta) ----------
if area_choice != "(Semua)":
    st.markdown(f"### 📊 BBC di {area_choice}")

    # Hitung jumlah cabang per BBC (urut desc)
    counts_series = filtered.groupby("bbc")["Cabang"].nunique().sort_values(ascending=False)
    sorted_bbc = counts_series.index.tolist()

    # Init selection state
    if "selected_bbc" not in st.session_state:
        st.session_state.selected_bbc = None

    # Render buttons (emoji dot color matches marker)
    cols = st.columns(3)
    for i, bbc in enumerate(sorted_bbc):
        color = palette.get(bbc, "gray")
        emoji = color_emoji(color)
        label = f"{emoji} {bbc} ({counts_series[bbc]})"
        if cols[i % 3].button(label, key=f"btn_bbc_{i}"):
            st.session_state.selected_bbc = bbc

    st.markdown("---")

    # Show cards for selected BBC
    if st.session_state.selected_bbc:
        sel = st.session_state.selected_bbc
        st.markdown(f"#### 🗂️ Cabang yang dikelola: **{sel}**")

        subset = filtered[filtered["bbc"] == sel].copy().sort_values("Cabang")
        color = palette.get(sel, "gray")

        # CSS for cards (fade-in, left border color)
        st.markdown(f"""
        <style>
        .cabang-card {{
            background: #f7f7f9;
            border-radius: 8px;
            padding: 12px 14px;
            margin-bottom: 10px;
            border-left: 6px solid {color};
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            animation: fadein 400ms ease-in;
        }}
        .cabang-title {{ font-weight: 700; font-size: 1.05rem; margin-bottom: 4px; }}
        .cabang-meta {{ color: #444; margin-bottom: 6px; }}
        .cabang-line {{ color: #333; }}
        .cabang-card:hover {{ box-shadow: 0 4px 14px rgba(0,0,0,0.10); }}
        @keyframes fadein {{
            from {{ opacity: 0; transform: translateY(4px); }}
            to {{ opacity: 1; transform: translateY(0); }}
        }}
        .export-box {{
            background: rgba(0,0,0,0.03);
            padding: 8px 10px;
            border-radius: 8px;
            border: 1px solid rgba(0,0,0,0.06);
            margin: 8px 0 16px 0;
        }}
        </style>
        """, unsafe_allow_html=True)

        # Export buttons (Excel / CSV) for selected BBC
        with st.container():
            st.markdown("<div class='export-box'>💾 Simpan daftar cabang (BBC terpilih)</div>", unsafe_allow_html=True)
            # Prepare export data
            export_cols = ["Cabang","Area","KotaKab","Kelas_Cabang_Lokasi","kelas_cabang_bbc","Status_Gedung","Izin_BI","lat","lon"]
            export_df = subset[export_cols].rename(columns={
                "KotaKab":"Kota/Kab",
                "Kelas_Cabang_Lokasi":"Kelas Lokasi",
                "kelas_cabang_bbc":"Kelas (BBC)",
                "Status_Gedung":"Status Gedung",
                "Izin_BI":"Izin BI",
                "lat":"Latitude",
                "lon":"Longitude"
            })
            # Excel
            from io import BytesIO
            bio = BytesIO()
            with pd.ExcelWriter(bio, engine="xlsxwriter") as writer:
                export_df.to_excel(writer, index=False, sheet_name="Cabang")
            st.download_button("⬇️ Unduh Excel", data=bio.getvalue(), file_name=f"cabang_{sel}.xlsx", mime="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet")
            # CSV
            st.download_button("⬇️ Unduh CSV", data=export_df.to_csv(index=False).encode("utf-8"), file_name=f"cabang_{sel}.csv", mime="text/csv")

        # Render cards
        for _, row in subset.iterrows():
            gmaps_q = urllib.parse.quote(f"{row['lat']},{row['lon']}")
            gm_url = f"https://www.google.com/maps?q={gmaps_q}"
            st.markdown(f"""
            <div class='cabang-card'>
                <div class='cabang-title'>📍 {row['Cabang']}</div>
                <div class='cabang-meta'>🏢 Area: {row.get('Area') or '—'} &nbsp;|&nbsp; 🏙️ Kota/Kab: {row.get('KotaKab') or '—'}</div>
                <div class='cabang-line'>🏷️ Kelas Lokasi: {row.get('Kelas_Cabang_Lokasi') or '—'} &nbsp;|&nbsp; 🏷️ Kelas BBC: {row.get('kelas_cabang_bbc') or '—'}</div>
                <div class='cabang-line'>📘 Status Gedung: {row.get('Status_Gedung') or '—'} &nbsp;|&nbsp; 📗 Izin BI: {row.get('Izin_BI') or '—'}</div>
                <div class='cabang-line'>🌐 <a href='{gm_url}' target='_blank'>Lihat di Google Maps</a> &nbsp;&nbsp; 🔢 Koordinat: {row['lat']}, {row['lon']}</div>
            </div>
            """, unsafe_allow_html=True)

# --- Footer note
st.caption("Versi v3 • Data dari SQLite • Marker warna adaptif • Panel BBC interaktif • Kartu detail + ekspor")
