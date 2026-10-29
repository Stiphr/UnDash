#!/usr/bin/env python3
"""Builds undash.plg from src/ (every file is inlined, as Unraid plugins require, so installing needs no extra downloads).
Usage: python3 build.py [VERSION]      default version = today's date, e.g. 2026.10.29 (add a letter for a second release that day: 2026.10.29a)
"""
import sys, pathlib, datetime, hashlib, base64, textwrap

root = pathlib.Path(__file__).parent
src = root / 'src'
args = [a for a in sys.argv[1:] if not a.startswith('--')]
version = args[0] if args else datetime.date.today().strftime('%Y.%m.%d')
files = [
    ('UnDash.page', '0644'), ('UnDashMain.page', '0644'),
    ('include/lib.php', '0644'), ('include/config.php', '0644'), ('include/system.php', '0644'), ('include/docker.php', '0644'),
    ('include/vm.php', '0644'), ('include/services.php', '0644'), ('include/import.php', '0644'), ('include/transfer.php', '0644'),
    ('include/ops.php', '0644'), ('include/auth.php', '0644'), ('include/unraid_api.php', '0644'), ('include/media.php', '0644'), ('include/arr.php', '0644'),
    ('include/downloads.php', '0644'), ('include/api.php', '0644'), ('handoff.php', '0644'), ('standalone/router.php', '0644'),
    ('scripts/oscheck.php', '0755'), ('scripts/rc.undash', '0755'), ('assets/app.css', '0644'), ('assets/app.js', '0644'),
]
changes = (root / 'CHANGES.txt').read_text().strip()

out = ["""<?xml version='1.0' standalone='yes'?>
<!DOCTYPE PLUGIN [
<!ENTITY name    "undash">
<!ENTITY author  "Stiphr">
<!ENTITY version "%s">
<!ENTITY emhttp  "/usr/local/emhttp/plugins/&name;">
<!ENTITY flash   "/boot/config/plugins/&name;">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Stiphr/UnDash/main/undash.plg">
]>
<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" launch="UnDash" min="6.12.0" icon="undash.png" support="https://github.com/Stiphr/UnDash/issues">

<CHANGES>
%s
</CHANGES>
""" % (version, changes)]

for rel, mode in files:
    text = (src / rel).read_text()
    assert ']]>' not in text, rel
    if rel == 'include/lib.php':
        assert '@@VERSION@@' in text
        text = text.replace('@@VERSION@@', version)
    # Unraid only overwrites an existing file on update when a hash is given and differs, so every file carries one.
    # The plugin manager writes trim(INLINE) + newline, so hash exactly that.
    written = text.strip(" \t\n\r\0\x0b") + "\n"
    sha = hashlib.sha256(written.encode()).hexdigest()
    out.append('<FILE Name="&emhttp;/%s" Mode="%s">\n<INLINE><![CDATA[%s]]></INLINE>\n<SHA256>%s</SHA256>\n</FILE>\n' % (rel, mode, text, sha))

# The icon is binary, so it travels as base64 and is unpacked by a script (re-run at every boot, so it is always there).
icon = (root / 'assets' / 'icon.png').read_bytes()
icon_b64 = '\n'.join(textwrap.wrap(base64.b64encode(icon).decode(), 76))
icon_sha = hashlib.sha256(icon).hexdigest()
out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &emhttp;/images
echo '""" + icon_b64 + """' | base64 -d > /tmp/undash-icon.png 2>/dev/null
if [ "$(sha256sum /tmp/undash-icon.png | cut -d' ' -f1)" = \"""" + icon_sha + """\" ]; then mv -f /tmp/undash-icon.png &emhttp;/images/undash.png; chmod 644 &emhttp;/images/undash.png; else echo "icon check failed, skipped"; fi
rm -f /tmp/undash-icon.png
</INLINE>
</FILE>
""")

out.append("""<FILE Run="/bin/bash">
<INLINE>
mkdir -p &flash;
chmod 755 &emhttp;/scripts/oscheck.php &emhttp;/scripts/rc.undash
chmod 600 &flash;/secrets.json 2>/dev/null
# first install: the documented defaults (configuration in appdata, standalone port 9099); an existing file is never changed
[ -f &flash;/undash.cfg ] || printf 'CONFIG_DIR="/mnt/user/appdata/undash"\nPORT="9099"\nBIND="0.0.0.0"\nSTANDALONE="yes"\n' > &flash;/undash.cfg
# keep sessions and history across a plugin update, drop caches
rm -f /tmp/undash/inspect.json /tmp/undash/vminfo.json /tmp/undash/rates.json /tmp/undash/cg.json
&emhttp;/scripts/rc.undash restart
echo ""
echo "----------------------------------------------------"
echo " UnDash &version; installed"
echo " Open it from the UnDash tab in Unraid's top menu,"
echo " or on its own port (default 9099) when signed in to Unraid"
echo "----------------------------------------------------"
echo ""
</INLINE>
</FILE>

<FILE Run="/bin/bash" Method="remove">
<INLINE>
if [ -x &emhttp;/scripts/rc.undash ]; then &emhttp;/scripts/rc.undash stop; fi
rm -rf &emhttp; /tmp/undash
echo "UnDash removed. Your settings were kept in &flash; and in the configuration directory (default /mnt/user/appdata/undash)."

</INLINE>
</FILE>

</PLUGIN>
""")
(root / 'undash.plg').write_text('\n'.join(out))
print('built undash.plg', version)
