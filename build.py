#!/usr/bin/env python3
"""Builds undash.plg from src/ (every file is inlined, as Unraid plugins require, so installing needs no extra downloads).
Usage: python3 build.py VERSION      x.y.z, e.g. 0.5.1 (the release tag is vX.Y.Z)
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
    ('include/downloads.php', '0644'), ('include/ignores.php', '0644'), ('include/alerts.php', '0644'), ('include/health.php', '0644'), ('include/playing.php', '0644'), ('include/requests.php', '0644'), ('include/api.php', '0644'), ('handoff.php', '0644'), ('standalone/router.php', '0644'),
    ('scripts/oscheck.php', '0755'), ('scripts/alertcheck.php', '0755'), ('scripts/rc.undash', '0755'), ('assets/app.css', '0644'), ('assets/app.js', '0644'),
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

# Binary files (the icon, the Inter font) travel as base64 and are unpacked by a script (re-run at every boot, so they are always there).
def binary(data, dest, what):
    b64 = '\n'.join(textwrap.wrap(base64.b64encode(data).decode(), 76))
    sha = hashlib.sha256(data).hexdigest()
    tmp = '/tmp/undash-' + what
    return """<FILE Run="/bin/bash">
<INLINE>
mkdir -p $(dirname """ + dest + """)
echo '""" + b64 + """' | base64 -d > """ + tmp + """ 2>/dev/null
if [ "$(sha256sum """ + tmp + """ | cut -d' ' -f1)" = \"""" + sha + """\" ]; then mv -f """ + tmp + """ """ + dest + """; chmod 644 """ + dest + """; else echo \"""" + what + """ check failed, skipped\"; fi
rm -f """ + tmp + """
</INLINE>
</FILE>
"""
out.append(binary((root / 'assets' / 'icon.png').read_bytes(), '&emhttp;/images/undash.png', 'icon'))
out.append(binary((src / 'assets' / 'inter.woff2').read_bytes(), '&emhttp;/assets/inter.woff2', 'font'))

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
