import struct
import sys
import zipfile

src = sys.argv[1]
dst = sys.argv[2]

with zipfile.ZipFile(src, "r") as zin:
    manifest = bytearray(zin.read("AndroidManifest.xml"))

    def u16(buf, off):
        return struct.unpack_from("<H", buf, off)[0]

    def u32(buf, off):
        return struct.unpack_from("<I", buf, off)[0]

    def p32(buf, off, val):
        struct.pack_into("<I", buf, off, val)

    # Android binary XML: file header (8 bytes), then string pool.
    sp = 8
    if u16(manifest, sp) != 0x0001:
        raise RuntimeError("String pool not found")

    header_size = u16(manifest, sp + 2)
    old_chunk_size = u32(manifest, sp + 4)
    string_count = u32(manifest, sp + 8)
    flags = u32(manifest, sp + 16)
    strings_start = u32(manifest, sp + 20)
    styles_start = u32(manifest, sp + 24)

    if flags & 0x100:
        raise RuntimeError("Unexpected UTF-8 string pool")

    offsets_pos = sp + header_size
    offsets = [u32(manifest, offsets_pos + i * 4) for i in range(string_count)]
    strings_base = sp + strings_start

    def decode_utf16(rel):
        pos = strings_base + rel
        n = u16(manifest, pos)
        prefix = 2
        if n & 0x8000:
            n2 = u16(manifest, pos + 2)
            n = ((n & 0x7fff) << 16) | n2
            prefix = 4
        start = pos + prefix
        end = start + n * 2
        return manifest[start:end].decode("utf-16le"), pos, end + 2

    target_index = None
    old_start = old_end = None
    for i, rel in enumerate(offsets):
        value, a, b = decode_utf16(rel)
        if value == "com.google":
            target_index = i
            old_start, old_end = a, b
            break

    if target_index is None:
        raise RuntimeError("Exact manifest string com.google not found")

    replacement_text = "app.revanced"
    replacement = (
        struct.pack("<H", len(replacement_text))
        + replacement_text.encode("utf-16le")
        + b"\x00\x00"
    )
    delta = len(replacement) - (old_end - old_start)
    if delta % 4:
        raise RuntimeError("Unexpected string-pool alignment delta")

    out_manifest = bytearray()
    out_manifest += manifest[:old_start]
    out_manifest += replacement
    out_manifest += manifest[old_end:]

    target_rel = offsets[target_index]
    for i, rel in enumerate(offsets):
        if rel > target_rel:
            p32(out_manifest, offsets_pos + i * 4, rel + delta)

    p32(out_manifest, sp + 4, old_chunk_size + delta)
    if styles_start:
        p32(out_manifest, sp + 24, styles_start + delta)
    p32(out_manifest, 4, u32(manifest, 4) + delta)

    with zipfile.ZipFile(dst, "w", allowZip64=True) as zout:
        for item in zin.infolist():
            payload = bytes(out_manifest) if item.filename == "AndroidManifest.xml" else zin.read(item.filename)
            info = zipfile.ZipInfo(item.filename, item.date_time)
            info.compress_type = item.compress_type
            info.comment = item.comment
            info.extra = item.extra
            info.internal_attr = item.internal_attr
            info.external_attr = item.external_attr
            info.create_system = item.create_system
            info.flag_bits = item.flag_bits & ~0x08
            zout.writestr(info, payload)

print("requiredAccountType manifest string: com.google -> app.revanced")
