import streamlit as st
import pandas as pd
import numpy as np
import base64
import streamlit.components.v1 as components

st.set_page_config(page_title="Leaderboard Cabang & Pegawai", layout="centered", initial_sidebar_state="collapsed")

CSS = r"""
/* --- user-provided theme/styles adapted for Streamlit container --- */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; }
.container { width: 100%; max-width: 400px; background: linear-gradient(135deg, #2a2a5e 0%, #3d3d8a 50%, #5a5ac8 100%); border-radius: 25px; padding: 20px; position: relative; overflow: hidden; box-shadow: 0 20px 40px rgba(0, 0, 0, 0.3);} 
.header{display:flex;justify-content:space-between;align-items:center;margin-bottom:20px}
.logo{display:flex;align-items:center;gap:8px;color:white;font-weight:600}
.logo-icon{width:30px;height:30px;background:linear-gradient(45deg,#ff6b6b,#ffd93d);border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:bold;color:white;font-size:14px}
.title{text-align:center;margin-bottom:20px}
.title-main{background:linear-gradient(45deg,#00f5ff,#00d4ff,#0099ff);-webkit-background-clip:text;-webkit-text-fill-color:transparent;font-size:24px;font-weight:bold}
.subtitle{display:flex;align-items:center;justify-content:center;gap:8px;color:#ffd700;font-size:16px;font-weight:600}
.top-three{display:flex;justify-content:center;align-items:end;gap:15px;margin-bottom:20px}
.top-item{display:flex;flex-direction:column;align-items:center;gap:8px}
.rank-label{background:rgba(255,255,255,0.2);color:white;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600}
.avatar-container{position:relative;width:60px;height:60px}
.top-1 .avatar-container{width:80px;height:80px}
.avatar{width:100%;height:100%;border-radius:50%;object-fit:cover;border:3px solid rgba(255,255,255,0.3)}
.top-1 .avatar{border:4px solid #ffd700;box-shadow:0 0 20px rgba(255,215,0,0.5)}
.username{color:white;font-size:12px;font-weight:500;text-align:center;max-width:80px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.leaderboard{background:rgba(255,255,255,0.08);border-radius:20px;padding:12px;backdrop-filter:blur(10px);border:1px solid rgba(255,255,255,0.12)}
.leaderboard-item{display:flex;align-items:center;gap:12px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.06)}
.rank-number{color:white;font-size:16px;font-weight:bold;min-width:30px;text-align:center}
.player-avatar{width:40px;height:40px;border-radius:50%;object-fit:cover;border:2px solid rgba(255,255,255,0.3)}
.player-name{color:white;font-size:14px;font-weight:500;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px}
.small{font-size:12px;color:rgba(255,255,255,0.8)}
/* small responsive */
@media (max-width:480px){.container{max-width:350px;padding:15px}.title-main{font-size:20px}.avatar-container{width:50px;height:50px}.top-1 .avatar-container{width:70px;height:70px}}
"""

HTML_TEMPLATE = """
<!doctype html>
<html>
<head>
<meta charset='utf-8'>
<meta name='viewport' content='width=device-width, initial-scale=1'>
<style>body{{background:transparent;}} .wrap{{display:flex;justify-content:center;padding:12px;}} {css}
</style>
</head>
<body>
<div class='wrap'>
  <div class='container'>
    <div class='header'>
      <div class='logo'><div class='logo-icon'>Y</div><span style='color:white'>Leaderboard</span></div>
      <div class='small'>Generated</div>
    </div>
    <div class='title'>
      <div class='title-main'>{title_main}</div>
      <div class='subtitle'>⏰ {subtitle}</div>
    </div>

    <div class='top-three'>
      {top_three_html}
    </div>

    <div class='leaderboard'>
      {leaderboard_items}
    </div>

    <div class='footer'>
      <div class='small'>Data last updated: {updated}</div>
      <div class='small'>Total: {total}</div>
    </div>
  </div>
</div>
<script>
// simple hover & click (pure js)
document.querySelectorAll('.leaderboard-item').forEach(item=>{{
  item.addEventListener('mouseenter',()=>{{item.style.background='rgba(255,255,255,0.04)';item.style.transform='translateX(6px)';item.style.transition='all 0.2s ease'}})
  item.addEventListener('mouseleave',()=>{{item.style.background='transparent';item.style.transform='translateX(0)'}})
}})
</script>
</body>
</html>
"""

# --- helper to build HTML pieces from pandas df ---

def build_top_three(df, name_col='name', avatar_col='avatar', value_col='AvgBalance'):
    top = df.sort_values(value_col, ascending=False).head(3).reset_index(drop=True)
    parts = []
    rank_names = ['top-1','top-2','top-3']
    for i, row in top.iterrows():
        rank = i+1
        cls = rank_names[i] if i < 3 else 'top-item'
        crown = "<div class='crown-icon'>👑</div>" if i==0 else ''
        part = f"<div class='top-item {cls}'><div class='rank-label'>TOP-{rank}</div><div class='avatar-container {'crown' if i==0 else ''}'>"
        part += f"<img src='{row.get(avatar_col, '')}' class='avatar' alt='{row.get(name_col)}'/>"
        part += crown
        part += f"</div><div class='username'>{row.get(name_col)}</div></div>"
        parts.append(part)
    return '\n'.join(parts)


def build_leaderboard_items(df, name_col='name', avatar_col='avatar', value_col='AvgBalance'):
    df = df.sort_values(value_col, ascending=False).reset_index(drop=True)
    items = []
    for i, row in df.iterrows():
        rank = i+1
        avatar = row.get(avatar_col, '')
        name = row.get(name_col, '')
        value = row.get(value_col, 0)
        items.append(f"<div class='leaderboard-item'><div class='rank-number'>{rank:02d}</div><img src='{avatar}' class='player-avatar' alt=''> <div class='player-name'>{name}</div><div class='small'>{value:,.0f}</div></div>")
    return '\n'.join(items)

# create an int64-capable random generator for large balances
rng = np.random.default_rng()

SAMPLE_CABANG = pd.DataFrame({
    'NamaKCP': ['KCP Denpasar','KCP Denpasar Barat','KCP Denpasar Timur','KCP Renon','KCP Nusa Dua','KCP Sanur','KCP Badung','KCP Gianyar','KCP Tabanan','KCP Singaraja'],
    # use int64 so values up to billions are allowed
    'AvgBalance': rng.integers(1_000_000_00, 30_000_000_00, size=10, dtype=np.int64).astype(float),
    'avatar': [
        'https://images.pexels.com/photos/4842579/pexels-photo-4842579.jpeg',
        'https://images.pexels.com/photos/17771076/pexels-photo-17771076.jpeg',
        'https://images.pexels.com/photos/4841182/pexels-photo-4841182.jpeg',
        'https://images.pexels.com/photos/4842566/pexels-photo-4842566.jpeg',
        'https://images.pexels.com/photos/7848986/pexels-photo-7848986.jpeg',
        'https://images.pexels.com/photos/7773731/pexels-photo-7773731.jpeg',
        'https://images.pexels.com/photos/4842563/pexels-photo-4842563.jpeg',
        'https://images.pexels.com/photos/4842571/pexels-photo-4842571.jpeg',
        'https://images.pexels.com/photos/4909465/pexels-photo-4909465.jpeg',
        'https://images.pexels.com/photos/4389460/pexels-photo-4389460.jpeg'
    ]
})

SAMPLE_PEGAWAI = pd.DataFrame({
    'Name': ['I Gede','Putri','Budi','Sari','Agus','Dewi','Rudi','Nina','Wayan','Ketut'],
    'AvgBalance': rng.integers(100_000_00, 5_000_000_00, size=10, dtype=np.int64).astype(float),
    'avatar': [
        'https://images.pexels.com/photos/220453/pexels-photo-220453.jpeg',
        'https://images.pexels.com/photos/415829/pexels-photo-415829.jpeg',
        'https://images.pexels.com/photos/614810/pexels-photo-614810.jpeg',
        'https://images.pexels.com/photos/774909/pexels-photo-774909.jpeg',
        'https://images.pexels.com/photos/91227/pexels-photo-91227.jpeg',
        'https://images.pexels.com/photos/1130626/pexels-photo-1130626.jpeg',
        'https://images.pexels.com/photos/2379005/pexels-photo-2379005.jpeg',
        'https://images.pexels.com/photos/3775523/pexels-photo-3775523.jpeg',
        'https://images.pexels.com/photos/774909/pexels-photo-774909.jpeg',
        'https://images.pexels.com/photos/1239291/pexels-photo-1239291.jpeg'
    ]
})


# --- UI ---
st.markdown("<style>div.block-container{padding-top:1rem;padding-bottom:1rem;}</style>", unsafe_allow_html=True)
st.markdown("<h3 style='text-align:center'>Leaderboard - Cabang & Pegawai</h3>", unsafe_allow_html=True)

# top horizontal menu via tabs (looks horizontal)
tab_cabang, tab_pegawai = st.tabs(["Cabang", "Pegawai"])

with tab_cabang:
    st.write("\n")
    uploaded = st.file_uploader("Upload CSV Cabang (opsional)", type=['csv'], key='cab_upload')
    if uploaded is not None:
        try:
            cabang_df = pd.read_csv(uploaded)
        except Exception as e:
            st.error(f"Gagal baca file: {e}")
            cabang_df = SAMPLE_CABANG.copy()
    else:
        cabang_df = SAMPLE_CABANG.copy()

    # normalize columns
    if 'Nama KCP' in cabang_df.columns:
        cabang_df = cabang_df.rename(columns={'Nama KCP':'NamaKCP'})
    # ensure numeric
    if 'AvgBalance' not in cabang_df.columns:
        st.info('Kolom AvgBalance tidak ditemukan, membuat contoh dari data jika tersedia...')
        if 'AvgBalance' not in cabang_df.columns:
            cabang_df['AvgBalance'] = np.random.randint(1_000_000_00, 30_000_000_00, size=len(cabang_df))
    cabang_df = cabang_df[['NamaKCP','AvgBalance']].copy()
    cabang_df['avatar'] = cabang_df.get('avatar', SAMPLE_CABANG['avatar'][:len(cabang_df)].tolist())
    cabang_df = cabang_df.rename(columns={'NamaKCP':'name'})

    top_html = build_top_three(cabang_df, name_col='name', avatar_col='avatar', value_col='AvgBalance')
    list_html = build_leaderboard_items(cabang_df, name_col='name', avatar_col='avatar', value_col='AvgBalance')
    html = HTML_TEMPLATE.format(css=CSS, title_main='Cabang', subtitle='Berdasarkan AvgBalance', top_three_html=top_html, leaderboard_items=list_html, updated=pd.Timestamp.now().strftime('%Y-%m-%d %H:%M'), total=len(cabang_df))
    components.html(html, height=760, scrolling=True)

with tab_pegawai:
    st.write("\n")
    uploaded_p = st.file_uploader("Upload CSV Pegawai (opsional)", type=['csv'], key='peg_upload')
    if uploaded_p is not None:
        try:
            peg_df = pd.read_csv(uploaded_p)
        except Exception as e:
            st.error(f"Gagal baca file: {e}")
            peg_df = SAMPLE_PEGAWAI.copy()
    else:
        peg_df = SAMPLE_PEGAWAI.copy()

    if 'Nama' in peg_df.columns and 'AvgBalance' in peg_df.columns:
        peg_df = peg_df.rename(columns={'Nama':'Name'})
    # Ensure columns
    if 'AvgBalance' not in peg_df.columns:
        peg_df['AvgBalance'] = np.random.randint(100_000_00,5_000_000_00,size=len(peg_df))
    if 'Name' not in peg_df.columns and 'name' in peg_df.columns:
        peg_df = peg_df.rename(columns={'name':'Name'})
    peg_df = peg_df.rename(columns={'Name':'name'})
    peg_df['avatar'] = peg_df.get('avatar', SAMPLE_PEGAWAI['avatar'][:len(peg_df)].tolist())

    top_html = build_top_three(peg_df, name_col='name', avatar_col='avatar', value_col='AvgBalance')
    list_html = build_leaderboard_items(peg_df, name_col='name', avatar_col='avatar', value_col='AvgBalance')
    html = HTML_TEMPLATE.format(css=CSS, title_main='Pegawai', subtitle='Berdasarkan AvgBalance', top_three_html=top_html, leaderboard_items=list_html, updated=pd.Timestamp.now().strftime('%Y-%m-%d %H:%M'), total=len(peg_df))
    components.html(html, height=760, scrolling=True)

# small instructions
st.markdown("""
**Cara pakai**:
- Jalankan `streamlit run streamlit_leaderboard_app.py`
- Tab `Cabang` menampilkan leaderboard berdasarkan `Nama KCP` dan `AvgBalance`.
- Tab `Pegawai` menampilkan leaderboard berdasarkan `Name` dan `AvgBalance`.
- Jika mau pakai data sendiri, upload CSV berisi kolom `Nama KCP`/`Name` dan `AvgBalance`.

Kustomisasi: sesuaikan kolom avatar jika ingin foto pegawai/kantor tampil.
""")
