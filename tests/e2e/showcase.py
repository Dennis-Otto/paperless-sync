# SPDX-FileCopyrightText: 2026 Dennis Otto
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The synthetic archive of the screenshots of the documentation.

The scenario showcase is a small household archive in Paperless; showcase-update is
the same archive a few days later, with one document renamed, one in the trash, one
excluded by a tag and two new ones, so that a dry-run shows every kind of change.
screenshots.mjs switches between them. Every name, date and text is invented.
"""

import hashlib

SCENARIOS = {"showcase", "showcase-update"}

CORRESPONDENTS = [
    {"id": 1, "name": "Example GmbH"},
    {"id": 2, "name": "Example Insurance"},
    {"id": 3, "name": "City Utilities"},
    {"id": 4, "name": "Example Bank"},
    {"id": 5, "name": "Tax Office"},
    {"id": 6, "name": "Riverside Clinic"},
    {"id": 7, "name": "Example Housing"},
]

DOCUMENT_TYPES = [
    {"id": 1, "name": "Invoice"},
    {"id": 2, "name": "Contract"},
    {"id": 3, "name": "Letter"},
    {"id": 4, "name": "Statement"},
    {"id": 5, "name": "Tax assessment"},
    {"id": 6, "name": "Payslip"},
]

TAGS = [
    {"id": 1, "name": "Inbox", "is_inbox_tag": True},
    {"id": 2, "name": "Private", "is_inbox_tag": False},
]

# The ID, the title, the correspondent, the document type and the date of every
# document. The content of a file keeps its first title, as a scan does when its
# metadata changes, so that a new title moves the file instead of writing it again.
ARCHIVE = [
    (123, "Example invoice", 1, 1, "2026-08-26"),
    (124, "Payslip August", 1, 6, "2026-08-31"),
    (125, "Employment contract", 1, 2, "2024-03-01"),
    (130, "Home insurance policy", 2, 2, "2026-01-15"),
    (131, "Car insurance policy", 2, 2, "2025-11-20"),
    (132, "Premium adjustment", 2, 3, "2026-02-03"),
    (140, "Electricity bill March", 3, 1, "2026-03-31"),
    (141, "Electricity bill June", 3, 1, "2026-06-30"),
    (142, "Water bill", 3, 1, "2025-12-15"),
    (150, "Account statement Q1", 4, 4, "2026-04-01"),
    (151, "Account statement Q2", 4, 4, "2026-07-01"),
    (160, "Income tax assessment 2025", 5, 5, "2026-05-12"),
    (170, "Dental treatment", 6, 1, "2026-02-18"),
    (180, "Rental agreement", 7, 2, "2024-09-01"),
]
# Still in the inbox of Paperless, so the app leaves it out until it is finished.
UNFINISHED = (190, "Scan 2026-10-07", None, None, "2026-10-07")
# What arrives in the days after the first synchronization.
LATER = [
    (143, "Electricity bill September", 3, 1, "2026-09-30"),
    (190, "Credit card terms", 4, 3, "2026-10-07"),
]
RENAMED = {132: "Premium adjustment 2026"}
TRASHED = {142: "2026-10-06T09:30:00+02:00"}
PRIVATE = {125}


def _document(entry, title=None, tags=None):
    document_id, first_title, correspondent, document_type, created = entry
    return {
        "id": document_id,
        "title": title or first_title,
        "correspondent": correspondent,
        "document_type": document_type,
        "storage_path": None,
        "tags": tags or [],
        "created": created,
        "added": f"{created}T09:00:00+02:00",
        "modified": f"{created}T09:00:00+02:00" if title is None else "2026-10-05T18:00:00+02:00",
        "original_file_name": f"scan-{document_id}.pdf",
        "archived_file_name": f"{document_id}.pdf",
        "mime_type": "application/pdf",
    }


def documents(scenario):
    """The active documents and the trash of a scenario."""
    if scenario == "showcase":
        return [_document(entry) for entry in ARCHIVE] + [_document(UNFINISHED, tags=[1])], []
    active, trash = [], []
    for entry in ARCHIVE + LATER:
        document_id = entry[0]
        if document_id in TRASHED:
            trashed = _document(entry)
            trashed["deleted_at"] = TRASHED[document_id]
            trash.append(trashed)
        else:
            active.append(_document(entry, RENAMED.get(document_id), [2] if document_id in PRIVATE else None))
    return active, trash


def _pdf_text(value):
    return value.replace("\\", "\\\\").replace("(", "\\(").replace(")", "\\)")


def content(document_id):
    """A one-page PDF of the document, with its first title, or None for an unknown ID."""
    entry = next((entry for entry in ARCHIVE + LATER + [UNFINISHED] if entry[0] == document_id), None)
    if entry is None:
        return None
    _, title, correspondent, document_type, created = entry
    sender = next((item["name"] for item in CORRESPONDENTS if item["id"] == correspondent), "Unknown sender")
    kind = next((item["name"] for item in DOCUMENT_TYPES if item["id"] == document_type), "Document")
    lines = [
        ("F2", 11, 72, 770, sender),
        ("F1", 10, 72, 755, "Example Street 1, 12345 Example City"),
        ("F1", 10, 430, 700, created),
        ("F2", 20, 72, 650, title),
        ("F1", 11, 72, 625, f"{kind} - Paperless document {document_id}"),
        ("F1", 11, 72, 580, "Dear customer,"),
        ("F1", 11, 72, 560, "this synthetic document fills the archive of the screenshots of Paperless Sync."),
        ("F1", 11, 72, 542, "It contains no personal data and belongs to no real person or company."),
        ("F1", 11, 72, 510, "Kind regards"),
        ("F1", 11, 72, 492, sender),
    ]
    stream = "".join(
        f"BT /{font} {size} Tf {x} {y} Td ({_pdf_text(text)}) Tj ET\n" for font, size, x, y, text in lines
    ).encode("latin-1")
    objects = [
        b"<< /Type /Catalog /Pages 2 0 R >>",
        b"<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
        b"<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R"
        b" /Resources << /Font << /F1 5 0 R /F2 6 0 R >> >> >>",
        b"<< /Length " + str(len(stream)).encode() + b" >>\nstream\n" + stream + b"endstream",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
        b"<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>",
    ]
    pdf = bytearray(b"%PDF-1.4\n")
    offsets = []
    for number, body in enumerate(objects, start=1):
        offsets.append(len(pdf))
        pdf += f"{number} 0 obj\n".encode() + body + b"\nendobj\n"
    xref = len(pdf)
    pdf += f"xref\n0 {len(objects) + 1}\n0000000000 65535 f \n".encode()
    pdf += b"".join(f"{offset:010d} 00000 n \n".encode() for offset in offsets)
    pdf += f"trailer\n<< /Size {len(objects) + 1} /Root 1 0 R >>\nstartxref\n{xref}\n%%EOF\n".encode()
    return bytes(pdf)


def checksum(document_id):
    data = content(document_id)
    return None if data is None else hashlib.sha256(data).hexdigest()
