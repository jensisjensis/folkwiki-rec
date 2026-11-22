import re
import sqlite3
import sys

db_path = sys.argv[1] if len(sys.argv) > 1 else "folkwiki_abc.sqlite3"
output_path = "folkwiki_relevant.abc"


def reduce_music_whitespace(line):
    """Reduziert Folgen von Leerzeichen/Tabs außerhalb von Anführungszeichen auf eins."""
    result = []
    in_quotes = False
    escaped = False
    i = 0

    while i < len(line):
        char = line[i]

        if in_quotes:
            result.append(char)

            if escaped:
                escaped = False
            elif char == "\\":
                escaped = True
            elif char == '"':
                in_quotes = False

            i += 1
            continue

        if char == '"':
            in_quotes = True
            result.append(char)
            i += 1
            continue

        if char in " \t":
            # Eine zusammenhängende Folge aus Leerzeichen/Tabs auf ein Leerzeichen reduzieren.
            while i < len(line) and line[i] in " \t":
                i += 1
            result.append(" ")
            continue

        result.append(char)
        i += 1

    return "".join(result)


with sqlite3.connect(db_path) as conn:
    rows = conn.execute("""
        SELECT rowid, page_name, block_index, abc
        FROM abc_blocks
        ORDER BY page_name COLLATE NOCASE, block_index, rowid
    """).fetchall()

with_source = []
without_source = []
skipped = []

for rowid, page_name, block_index, abc in rows:
    page_name = page_name or ""
    abc = abc or ""

    # Beispiel: Musik/1127 -> 1127
    match = re.search(r"/(\d+)$", page_name)
    if not match:
        skipped.append((rowid, page_name, block_index))
        continue

    online_number = match.group(1)

    if re.search(r"(?m)^\s*B\s*:", abc):
        with_source.append((abc, online_number))
    else:
        without_source.append((abc, online_number))

written = 0

with open(output_path, "w", encoding="utf-8", newline="\n") as out:
    # Zuerst Tunes mit B:-Angabe, danach Tunes ohne B:-Angabe.
    for group in (with_source, without_source):
        for abc, online_number in group:
            # PostScript-Blöcke samt Inhalt entfernen.
            abc = re.sub(
                r"(?ims)^[ \t]*%%beginps\b.*?^[ \t]*%%endps\b[^\n]*(?:\n|$)",
                "",
                abc,
            )

            lines = []

            for line in abc.splitlines():
                line = line.strip()

                # Leere Zeilen und alleinstehende Klammerzeilen entfernen.
                if not line or line == ")":
                    continue

                # Kommentar- und Direktivenzeilen entfernen.
                if line.startswith("%"):
                    continue

                lines.append(line)

            # Vorhandene I:-Felder entfernen.
            lines = [
                line for line in lines
                if not re.match(r"^I\s*:", line)
            ]

            # I:<Seitennummer> direkt nach X: einfügen.
            x_index = next(
                (
                    i for i, line in enumerate(lines)
                    if re.match(r"^X\s*:", line)
                ),
                None,
            )

            if x_index is None:
                lines.insert(0, f"I:{online_number}")
            else:
                lines.insert(x_index + 1, f"I:{online_number}")

            cleaned_lines = []

            for line in lines:
                # Bei ABC-Feldern Leerzeichen nach dem Doppelpunkt entfernen.
                # Beispiel: "T: Bräklåt" -> "T:Bräklåt".
                if re.match(r"^[A-Za-z]\s*:", line):
                    line = re.sub(
                        r"^([A-Za-z])\s*:\s*",
                        r"\1:",
                        line,
                    )
                else:
                    # In Notenzeilen mehrere Leerzeichen/Tabs auf eins reduzieren.
                    # Einzelne Leerzeichen bleiben als Notentrennung erhalten.
                    line = reduce_music_whitespace(line)

                cleaned_lines.append(line)

            # Tunes durch eine Leerzeile trennen.
            out.write("\n".join(cleaned_lines) + "\n\n")
            written += 1

print(f"Exportiert: {written} Tunes")
print(f"Mit B:-Angabe: {len(with_source)}")
print(f"Ohne B:-Angabe: {len(without_source)}")
print(f"Datei: {output_path}")

if skipped:
    print(f"Übersprungen, da keine Seitennummer erkannt wurde: {len(skipped)}")
    for rowid, page_name, block_index in skipped:
        print(f"  rowid={rowid}, page_name={page_name}, block_index={block_index}")
