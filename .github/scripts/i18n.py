#!/usr/bin/env python3
"""Translations for goo1-privacypilot (needs: pip install polib).

  i18n.py update   extract strings into languages/goo1-privacypilot.pot and
                   merge them into languages/goo1-privacypilot-de_DE.po
  i18n.py compile  build the .mo files (de_DE, plus de_DE_formal, de_AT and
                   de_CH as copies, so those locales are German too). The .mo
                   files are not committed; the release workflow runs this.
"""
import os
import re
import sys

import polib

PLUGIN = os.path.join(os.path.dirname(__file__), '..', '..', 'src', 'goo1-privacypilot')
LANG = os.path.join(PLUGIN, 'languages')
DOMAIN = 'goo1-privacypilot'
S = r"'((?:[^'\\]|\\.)*)'"
SINGLE = re.compile(r"\b(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e)\(\s*" + S + r"\s*,\s*'" + DOMAIN + r"'\s*\)", re.S)
PLURAL = re.compile(r"\b_n\(\s*" + S + r"\s*,\s*" + S + r"\s*,[^;]*?'" + DOMAIN + r"'\s*\)", re.S)
META = {
    'Project-Id-Version': 'PrivacyPilot',
    'MIME-Version': '1.0',
    'Content-Type': 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding': '8bit',
    'Plural-Forms': 'nplurals=2; plural=(n != 1);',
    'X-Domain': DOMAIN,
}


def unescape(s):
    return s.replace("\\'", "'").replace('\\\\', '\\')


def extract():
    pot = polib.POFile()
    pot.metadata = dict(META)
    seen = set()
    for dirpath, _, files in os.walk(PLUGIN):
        if 'plugin-update-checker' in dirpath or os.sep + 'tests' in dirpath:
            continue
        for name in sorted(files):
            if not name.endswith('.php'):
                continue
            path = os.path.join(dirpath, name)
            rel = os.path.relpath(path, PLUGIN)
            src = open(path, encoding='utf-8').read()
            for m in PLURAL.finditer(src):
                key = unescape(m.group(1))
                if key not in seen:
                    seen.add(key)
                    pot.append(polib.POEntry(msgid=key, msgid_plural=unescape(m.group(2)), msgstr_plural={0: '', 1: ''}, occurrences=[(rel, '')]))
            for m in SINGLE.finditer(src):
                key = unescape(m.group(1))
                if key not in seen:
                    seen.add(key)
                    pot.append(polib.POEntry(msgid=key, msgstr='', occurrences=[(rel, '')]))
    return pot


def main(cmd):
    po_path = os.path.join(LANG, DOMAIN + '-de_DE.po')
    if cmd == 'update':
        pot = extract()
        pot.save(os.path.join(LANG, DOMAIN + '.pot'))
        po = polib.pofile(po_path) if os.path.exists(po_path) else polib.POFile()
        po.metadata = dict(META, Language='de_DE')
        po.merge(pot)
        po.save(po_path)
        print('%d strings, %d untranslated' % (len(pot), len(po.untranslated_entries())))
    elif cmd == 'compile':
        po = polib.pofile(po_path)
        for loc in ('de_DE', 'de_DE_formal', 'de_AT', 'de_CH'):
            po.metadata['Language'] = loc
            po.save_as_mofile(os.path.join(LANG, '%s-%s.mo' % (DOMAIN, loc)))
        print('compiled, %d%% translated' % po.percent_translated())
    else:
        print(__doc__)
        sys.exit(1)


if __name__ == '__main__':
    main(sys.argv[1] if len(sys.argv) > 1 else '')
