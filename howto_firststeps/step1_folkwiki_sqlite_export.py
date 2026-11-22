#!/usr/bin/env python3
"""Findet ABC-Notenblöcke auf FolkWiki und exportiert sie als JSONL."""

import argparse
import json
import random
import re
import sqlite3
import sys
import time
from datetime import datetime, timezone
from email.utils import parsedate_to_datetime
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import Request, urlopen


BASE_URL = "http://www.folkwiki.se"
RECENT_CHANGES_URL = f"{BASE_URL}/Musik/RecentChanges?action=source"
USER_AGENT = "find_folkwiki_abc.py/2.0"

# ABC-Inhalte stehen in FolkWiki-Seiten zwischen diesen Markierungen.
MUSIC_BLOCK_RE = re.compile(
    r"\(:music:\s*(.*?)\s*\(:musicend:\)",
    flags=re.IGNORECASE | re.DOTALL,
)

# Musikseitenlinks aus der RecentChanges-Rohquelle.
MUSIC_PAGE_LINK_RE = re.compile(
    r"\[\[\s*(?:https?://[^/\]\s]+/)?(Musik/[^|\]#]+)",
    flags=re.IGNORECASE,
)

TITLE_RE = re.compile(r"(?im)^\s*T:\s*(.*?)\s*$")


class DownloadError(Exception):
    """Ein Download ist nach allen Versuchen fehlgeschlagen."""


def log(message):
    """Gibt eine zeitgestempelte Statusmeldung auf stderr aus."""
    timestamp = datetime.now().astimezone().strftime("%H:%M:%S")
    print(f"[{timestamp}] {message}", file=sys.stderr, flush=True)


def parse_retry_after(value):
    """Liest den HTTP-Header Retry-After als Sekundenwert oder Datum."""
    if not value:
        return None

    try:
        return max(0.0, float(value))
    except ValueError:
        pass

    try:
        retry_time = parsedate_to_datetime(value)
        if retry_time.tzinfo is None:
            retry_time = retry_time.replace(tzinfo=timezone.utc)

        seconds = (retry_time - datetime.now(timezone.utc)).total_seconds()
        return max(0.0, seconds)
    except (TypeError, ValueError, OverflowError):
        return None


def fetch_text(url, timeout, retries, max_bytes):
    """
    Lädt eine URL mit Timeout, Größenlimit und begrenzten Wiederholungen.

    Gibt bei HTTP 404 None zurück. Bei anderen endgültigen Fehlern wird
    DownloadError ausgelöst.
    """
    last_error = None

    for attempt in range(retries + 1):
        request = Request(
            url,
            headers={
                "User-Agent": USER_AGENT,
                "Accept": "text/plain,text/html;q=0.9,*/*;q=0.5",
            },
        )

        try:
            with urlopen(request, timeout=timeout) as response:
                content = response.read(max_bytes + 1)

            if len(content) > max_bytes:
                raise DownloadError(
                    f"Antwort überschreitet das Größenlimit "
                    f"({max_bytes} Bytes): {url}"
                )

            return content.decode("utf-8", errors="replace")

        except HTTPError as error:
            if error.code == 404:
                return None

            last_error = error

            # Bei diesen Statuscodes kann ein späterer Versuch helfen.
            retryable_codes = (408, 425, 429, 500, 502, 503, 504)
            if error.code not in retryable_codes:
                raise DownloadError(
                    f"HTTP-Fehler {error.code} bei {url}"
                ) from error

            wait_seconds = parse_retry_after(
                error.headers.get("Retry-After")
            )
            if wait_seconds is None:
                wait_seconds = min(60.0, 2.0 ** attempt)
                wait_seconds += random.uniform(0.0, 0.5)

        except (TimeoutError, OSError, URLError) as error:
            last_error = error
            wait_seconds = min(60.0, 2.0 ** attempt)
            wait_seconds += random.uniform(0.0, 0.5)

        if attempt < retries:
            log(
                f"Abruf fehlgeschlagen; neuer Versuch "
                f"in {wait_seconds:.1f} s: {url}"
            )
            time.sleep(wait_seconds)

    raise DownloadError(
        f"Abruf nach {retries + 1} Versuchen fehlgeschlagen: {url}"
    ) from last_error


def clean_page_name(raw_name):
    """Bereinigt einen Seitennamen und verwirft ungültige Ziele."""
    page_name = raw_name.strip().replace("\\", "")
    page_name = re.sub(r"\s+", "", page_name)

    if not page_name.lower().startswith("musik/"):
        return None

    if "?" in page_name or "\x00" in page_name:
        return None

    return page_name


def find_page_names(recent_changes_text):
    """Findet und sortiert eindeutige Musikseitennamen."""
    page_names = set()

    for match in MUSIC_PAGE_LINK_RE.finditer(recent_changes_text):
        page_name = clean_page_name(match.group(1))
        if page_name:
            page_names.add(page_name)

    return sorted(page_names, key=str.casefold)


def page_source_url(page_name):
    """Erzeugt die URL für die Rohquelle einer Seite."""
    encoded_name = quote(page_name, safe="/-_.()~")
    return f"{BASE_URL}/{encoded_name}?action=source"


def page_url(page_name):
    """Erzeugt die normale URL einer Seite für den Export."""
    encoded_name = quote(page_name, safe="/-_.()~")
    return f"{BASE_URL}/{encoded_name}"


def extract_abc_blocks(source):
    """Extrahiert ABC-Blöcke und, falls vorhanden, deren T:-Titel."""
    blocks = []

    for match in MUSIC_BLOCK_RE.finditer(source):
        abc_text = match.group(1).strip()
        if not abc_text:
            continue

        title_match = TITLE_RE.search(abc_text)
        title = title_match.group(1).strip() if title_match else ""

        blocks.append((title, abc_text))

    return blocks


def open_database(database_path):
    """Öffnet die SQLite-Datenbank und richtet die Tabellen ein."""
    database_path.parent.mkdir(parents=True, exist_ok=True)

    connection = sqlite3.connect(database_path)
    connection.execute("PRAGMA journal_mode=WAL")
    connection.execute("PRAGMA synchronous=NORMAL")

    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS pages (
            page_name TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            fetched_at TEXT NOT NULL,
            error TEXT
        )
        """
    )

    connection.execute(
        """
        CREATE TABLE IF NOT EXISTS abc_blocks (
            page_name TEXT NOT NULL,
            block_index INTEGER NOT NULL,
            title TEXT NOT NULL,
            abc TEXT NOT NULL,
            PRIMARY KEY (page_name, block_index)
        )
        """
    )

    connection.commit()
    return connection


def save_page_result(connection, page_name, status, blocks, error=None):
    """Speichert Seitenstatus und gefundene ABC-Blöcke in einer Transaktion."""
    fetched_at = datetime.now(timezone.utc).isoformat()

    with connection:
        connection.execute(
            """
            INSERT INTO pages (page_name, status, fetched_at, error)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(page_name) DO UPDATE SET
                status = excluded.status,
                fetched_at = excluded.fetched_at,
                error = excluded.error
            """,
            (page_name, status, fetched_at, error),
        )

        connection.execute(
            "DELETE FROM abc_blocks WHERE page_name = ?",
            (page_name,),
        )

        for index, (title, abc_text) in enumerate(blocks, start=1):
            connection.execute(
                """
                INSERT INTO abc_blocks (page_name, block_index, title, abc)
                VALUES (?, ?, ?, ?)
                """,
                (page_name, index, title, abc_text),
            )


def page_is_done(connection, page_name):
    """Prüft, ob diese Seite bereits erfolgreich verarbeitet wurde."""
    row = connection.execute(
        "SELECT status FROM pages WHERE page_name = ?",
        (page_name,),
    ).fetchone()

    return bool(row and row[0] in ("ok", "not_found"))


def export_jsonl(connection, output_path):
    """Exportiert alle gespeicherten ABC-Blöcke als JSONL."""
    output_path.parent.mkdir(parents=True, exist_ok=True)
    temporary_path = output_path.with_suffix(output_path.suffix + ".tmp")
    count = 0

    rows = connection.execute(
        """
        SELECT b.page_name, b.block_index, b.title, b.abc
        FROM abc_blocks AS b
        JOIN pages AS p ON p.page_name = b.page_name
        WHERE p.status = 'ok'
        ORDER BY b.page_name COLLATE NOCASE, b.block_index
        """
    )

    with temporary_path.open("w", encoding="utf-8", newline="\n") as output:
        for name, block_index, title, abc_text in rows:
            record = {
                "page": name,
                "page_url": page_url(name),
                "block": block_index,
                "title": title,
                "abc": abc_text,
            }
            output.write(json.dumps(record, ensure_ascii=False) + "\n")
            count += 1

    temporary_path.replace(output_path)
    return count


def parse_args():
    parser = argparse.ArgumentParser(
        description="ABC-Notenblöcke von FolkWiki herunterladen."
    )

    parser.add_argument(
        "--output",
        type=Path,
        default=Path("folkwiki_abc.jsonl"),
        help="JSONL-Ausgabedatei",
    )
    parser.add_argument(
        "--db",
        type=Path,
        default=Path("folkwiki_abc.sqlite3"),
        help="SQLite-Datei für den Fortschritt",
    )
    parser.add_argument(
        "--timeout",
        type=float,
        default=20.0,
        help="Timeout pro HTTP-Anfrage in Sekunden (Standard: 20)",
    )
    parser.add_argument(
        "--retries",
        type=int,
        default=4,
        help="Zusätzliche Versuche pro Anfrage (Standard: 4)",
    )
    parser.add_argument(
        "--delay",
        type=float,
        default=0.5,
        help="Pause zwischen Seitenanfragen in Sekunden (Standard: 0.5)",
    )
    parser.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Maximale Zahl neuer Seiten pro Lauf",
    )
    parser.add_argument(
        "--max-bytes",
        type=int,
        default=10 * 1024 * 1024,
        help="Maximale Antwortgröße pro Seite in Bytes (Standard: 10 MiB)",
    )
    parser.add_argument(
        "--refresh",
        action="store_true",
        help="Gespeicherten Fortschritt löschen und Seiten neu laden",
    )

    return parser.parse_args()


def main():
    args = parse_args()

    if args.timeout <= 0:
        print("--timeout muss größer als 0 sein.", file=sys.stderr)
        return 2

    if args.retries < 0:
        print("--retries darf nicht negativ sein.", file=sys.stderr)
        return 2

    if args.delay < 0:
        print("--delay darf nicht negativ sein.", file=sys.stderr)
        return 2

    if args.limit is not None and args.limit < 1:
        print("--limit muss mindestens 1 sein.", file=sys.stderr)
        return 2

    if args.max_bytes < 1:
        print("--max-bytes muss mindestens 1 sein.", file=sys.stderr)
        return 2

    connection = open_database(args.db)

    try:
        if args.refresh:
            log("Lösche bisherigen Fortschritt.")
            with connection:
                connection.execute("DELETE FROM abc_blocks")
                connection.execute("DELETE FROM pages")

        log(f"Lade Seitenliste: {RECENT_CHANGES_URL}")

        try:
            recent_changes = fetch_text(
                RECENT_CHANGES_URL,
                timeout=args.timeout,
                retries=args.retries,
                max_bytes=args.max_bytes,
            )
        except DownloadError as error:
            log(str(error))
            return 1

        if recent_changes is None:
            log("RecentChanges wurde mit HTTP 404 beantwortet.")
            return 1

        all_pages = find_page_names(recent_changes)
        if not all_pages:
            log("Keine Musikseiten in RecentChanges gefunden.")
            return 1

        pending_pages = [
            name for name in all_pages if not page_is_done(connection, name)
        ]

        if args.limit is not None:
            pending_pages = pending_pages[:args.limit]

        log(
            f"{len(all_pages)} Seiten gefunden; "
            f"{len(pending_pages)} werden in diesem Lauf abgerufen."
        )

        failed_count = 0

        for index, name in enumerate(pending_pages, start=1):
            source_url = page_source_url(name)
            log(f"[{index}/{len(pending_pages)}] {name}")

            try:
                source = fetch_text(
                    source_url,
                    timeout=args.timeout,
                    retries=args.retries,
                    max_bytes=args.max_bytes,
                )

                if source is None:
                    save_page_result(connection, name, "not_found", [])
                    log("Seite nicht gefunden (404).")
                else:
                    blocks = extract_abc_blocks(source)
                    save_page_result(connection, name, "ok", blocks)
                    log(f"{len(blocks)} ABC-Block/Blöcke gespeichert.")

            except DownloadError as error:
                failed_count += 1
                log(f"Seite nicht abgeschlossen: {error}")
                # Fehlerhafte Seiten bleiben offen und werden beim nächsten
                # Start erneut versucht.

            if args.delay:
                time.sleep(args.delay)

        block_count = export_jsonl(connection, args.output)
        log(f"Exportiert: {block_count} ABC-Blöcke nach {args.output}")

        if failed_count:
            log(
                f"{failed_count} Seite(n) sind fehlgeschlagen. "
                "Starte das Skript erneut, um sie nochmals zu versuchen."
            )
            return 2

        return 0

    except KeyboardInterrupt:
        log("Abbruch. Der gespeicherte Fortschritt bleibt erhalten.")

        try:
            block_count = export_jsonl(connection, args.output)
            log(f"Bisheriger Export: {block_count} ABC-Blöcke.")
        except OSError as error:
            log(f"Export beim Abbruch fehlgeschlagen: {error}")

        return 130

    finally:
        connection.close()


if __name__ == "__main__":
    raise SystemExit(main())
