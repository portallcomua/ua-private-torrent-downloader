import os
import re
import subprocess
import tempfile
import zipfile

from pathlib import Path

from fastapi import FastAPI, Header, HTTPException
from fastapi.responses import FileResponse
from pydantic import BaseModel

import qbittorrentapi


# =====================================================
# НАЛАШТУВАННЯ
# =====================================================

API_TOKEN = os.environ["API_TOKEN"]

QBIT_HOST = os.getenv(
    "QBIT_HOST",
    "http://127.0.0.1:8080"
)

QBIT_USER = os.getenv(
    "QBIT_USER",
    "admin"
)

QBIT_PASS = os.environ["QBIT_PASS"]

DOWNLOAD_DIR = Path(
    os.getenv("DOWNLOAD_DIR", "/downloads")
)

WG_INTERFACE = os.getenv(
    "WG_INTERFACE",
    "tun0"
)

DOWNLOAD_DIR.mkdir(
    parents=True,
    exist_ok=True
)


# =====================================================
# API
# =====================================================

app = FastAPI(
    title="UA Private Torrent API",
    docs_url=None,
    redoc_url=None
)


class AddRequest(BaseModel):
    source: str


# =====================================================
# АВТОРИЗАЦІЯ
# =====================================================

def check_auth(authorization):

    expected = "Bearer " + API_TOKEN

    if not authorization or authorization != expected:

        raise HTTPException(
            status_code=401,
            detail="Невірний API-токен"
        )


# =====================================================
# ПЕРЕВІРКА VPN
# =====================================================

def vpn_status():

    try:

        interface = subprocess.check_output(
            [
                "ip",
                "-o",
                "link",
                "show",
                "dev",
                WG_INTERFACE
            ],
            stderr=subprocess.STDOUT,
            text=True,
            timeout=5
        )

        route = subprocess.check_output(
            [
                "ip",
                "route",
                "get",
                "1.1.1.1"
            ],
            stderr=subprocess.STDOUT,
            text=True,
            timeout=5
        )

        interface_up = (
            "UP" in interface.split("<", 1)[-1].split(">", 1)[0]
        )

        route_via_vpn = (
            f"dev {WG_INTERFACE}" in route
        )

        result = interface_up and route_via_vpn

        if result:

            return True, "VPN-маршрут підтверджений."

        return False, "VPN не підтверджений. Завантаження заблоковано."

    except Exception:

        return False, "Не вдалося перевірити VPN."


def require_vpn():

    ok, detail = vpn_status()

    if not ok:

        raise HTTPException(
            status_code=503,
            detail=detail
        )


# =====================================================
# TORRENT CLIENT
# =====================================================

def get_client():

    try:

        q = qbittorrentapi.Client(
            host=QBIT_HOST,
            username=QBIT_USER,
            password=QBIT_PASS,
            REQUESTS_ARGS={
                "timeout": 20
            }
        )

        q.auth_log_in()

        return q

    except Exception:

        raise HTTPException(
            status_code=502,
            detail="Не вдалося підключитися до торрент-клієнта."
        )


# =====================================================
# ПЕРЕВІРКА HASH
# =====================================================

def validate_hash(value):

    if not re.fullmatch(
        r"[a-fA-F0-9]{40}",
        value or ""
    ):

        raise HTTPException(
            status_code=400,
            detail="Невірний ідентифікатор торрента."
        )

    return value.lower()


# =====================================================
# СТАН VPN
# =====================================================

@app.get("/api/status")
def api_status(
    authorization: str = Header(default="")
):

    check_auth(authorization)

    ok, detail = vpn_status()

    return {
        "vpn_ok": ok,
        "vpn_detail": detail
    }


# =====================================================
# СПИСОК ЗАВАНТАЖЕНЬ
# =====================================================

@app.get("/api/torrents")
def torrents(
    authorization: str = Header(default="")
):

    check_auth(authorization)

    require_vpn()

    q = get_client()

    result = []

    for torrent in q.torrents_info():

        progress = float(
            torrent.progress
        )

        state = str(
            torrent.state
        )

        completed = progress >= 1.0

        result.append({

            "hash": torrent.hash,

            "name": torrent.name,

            "state": (
                "completed"
                if completed
                else state
            ),

            "state_text": state,

            "progress": progress,

            "dlspeed_text": (
                f"{int(torrent.dlspeed)} B/s"
            ),

            "size_text": (
                f"{int(torrent.size)} B"
            )

        })

    return {
        "items": result
    }


# =====================================================
# ДОДАВАННЯ TORRENT
# =====================================================

@app.post("/api/add")
def add_torrent(
    payload: AddRequest,
    authorization: str = Header(default="")
):

    check_auth(authorization)

    require_vpn()

    source = payload.source.strip()

    is_magnet = source.startswith(
        "magnet:?xt=urn:btih:"
    )

    is_torrent_url = (
        source.startswith("https://")
        and source.lower().split("?")[0].endswith(".torrent")
    )

    if not (is_magnet or is_torrent_url):

        raise HTTPException(
            status_code=400,
            detail="Потрібне magnet-посилання або HTTPS URL файлу .torrent."
        )

    q = get_client()

    try:

        q.torrents_add(
            urls=source,
            save_path=str(DOWNLOAD_DIR),
            is_paused=False,
            ratioLimit=0.01,
            seedingTimeLimit=1
        )

    except Exception as error:

        raise HTTPException(
            status_code=502,
            detail="Помилка додавання торрента: " + str(error)[:150]
        )

    return {
        "ok": True,
        "message": "Торрент передано клієнту."
    }


# =====================================================
# ВИДАЛЕННЯ TORRENT І ФАЙЛІВ
# =====================================================

@app.delete("/api/torrents/{infohash}")
def delete_torrent(
    infohash: str,
    authorization: str = Header(default="")
):

    check_auth(authorization)

    require_vpn()

    torrent_hash = validate_hash(
        infohash
    )

    q = get_client()

    try:

        q.torrents_delete(
            torrent_hashes=torrent_hash,
            delete_files=True
        )

    except Exception:

        raise HTTPException(
            status_code=502,
            detail="Не вдалося видалити торрент."
        )

    return {
        "ok": True,
        "message": "Торрент і його файли видалено."
    }


# =====================================================
# ЗАВАНТАЖЕННЯ ГОТОВОГО ФАЙЛУ
# =====================================================

@app.get("/api/download/{infohash}")
def download_file(
    infohash: str,
    authorization: str = Header(default="")
):

    check_auth(authorization)

    require_vpn()

    torrent_hash = validate_hash(
        infohash
    )

    q = get_client()

    torrents = q.torrents_info(
        torrent_hashes=torrent_hash
    )

    if not torrents:

        raise HTTPException(
            status_code=404,
            detail="Торрент не знайдено."
        )

    torrent = torrents[0]

    if float(torrent.progress) < 1.0:

        raise HTTPException(
            status_code=409,
            detail="Завантаження ще не завершено."
        )

    base = Path(
        torrent.content_path
    )

    if not base.exists():

        base = (
            Path(torrent.save_path)
            / torrent.name
        )

    if base.is_file():

        return FileResponse(
            path=str(base),
            filename=base.name,
            media_type="application/octet-stream"
        )

    if base.is_dir():

        zip_path = (
            Path(tempfile.gettempdir())
            / f"uaptd-{torrent_hash}.zip"
        )

        with zipfile.ZipFile(
            zip_path,
            "w",
            zipfile.ZIP_DEFLATED
        ) as archive:

            for file in base.rglob("*"):

                if file.is_file():

                    archive.write(
                        file,
                        file.relative_to(base.parent)
                    )

        return FileResponse(
            path=str(zip_path),
            filename=f"{base.name}.zip",
            media_type="application/zip",
            background=None
        )

    raise HTTPException(
        status_code=404,
        detail="Готовий файл не знайдено."
    )
