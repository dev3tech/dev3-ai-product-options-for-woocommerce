#!/usr/bin/env python3
"""Generates tests/fixtures/price-cases.json: 100+ price-engine fixture cases.

Expected values come from an independent Python reference of the pricing rules. Formulas are NOT
parsed here: each formula case carries a hand-written Python lambda with the same meaning, so the
PHP and JS parsers are both checked against arithmetic written separately.

Run: python3 tests/fixtures/generate-price-cases.py
"""
import json
import math
import os
import re

CASES = []

KINDS = {
    'text': 'text', 'textarea': 'text', 'email': 'text', 'url': 'text', 'tel': 'text',
    'number': 'number', 'range': 'number', 'select': 'choice', 'radio': 'choice', 'checkboxes': 'multi',
    'color_swatch': 'choice', 'image_swatch': 'choice', 'image_picker': 'choice', 'checkbox': 'bool',
    'toggle': 'bool', 'date': 'date', 'time': 'date', 'datetime': 'date', 'file': 'file', 'color': 'color',
    'hidden': 'hidden', 'repeater': 'repeater', 'section': 'layout', 'step': 'layout', 'paragraph': 'layout',
}


def F(fid, ftype, **kw):
    f = {
        'id': fid, 'type': ftype, 'key': kw.pop('key', fid), 'label': kw.pop('label', fid.upper()),
        'help': '', 'tooltip': '', 'placeholder': '', 'required': False, 'default': kw.pop('default', ''),
        'width': 'full', 'class': '', 'price_type': kw.pop('price_type', 'none'), 'price': kw.pop('price', 0),
        'formula': kw.pop('formula', ''), 'tiers': kw.pop('tiers', []), 'charge': kw.pop('charge', 'item'),
        'free_chars': kw.pop('free_chars', 0), 'count_space': kw.pop('count_space', False),
        'conditions': kw.pop('conditions', {'enabled': False, 'action': 'show', 'match': 'all', 'rules': []}),
        'i18n': {},
    }
    if KINDS[ftype] in ('choice', 'multi'):
        f['options'] = kw.pop('options', [])
        f['max_select'] = 0
    if ftype == 'repeater':
        f['subfields'] = kw.pop('subfields', [])
        f['min_rows'] = 0
        f['max_rows'] = 10
        f['button_label'] = ''
    if ftype in ('section', 'step', 'paragraph'):
        f['description'] = ''
        f['collapsible'] = False
    f.update(kw)
    return f


def O(oid, label, price=0, price_type='flat', value=None):
    return {'id': oid, 'label': label, 'value': value if value is not None else label.lower(), 'price': price,
            'price_type': price_type, 'image': '', 'color': '', 'default': False}


def cond(rules, action='show', match='all'):
    return {'enabled': True, 'action': action, 'match': match,
            'rules': [{'field': r[0], 'op': r[1], 'value': r[2] if len(r) > 2 else ''} for r in rules]}


# ------------------------------------------------------------------ reference engine

def kind(t):
    return KINDS[t]


def is_numeric(v):
    if isinstance(v, bool):
        return False
    if isinstance(v, (int, float)):
        return True
    return isinstance(v, str) and re.fullmatch(r'[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?', v.strip() or 'x') is not None


def empty_value(f):
    k = kind(f['type'])
    return None if k == 'number' else [] if k in ('multi', 'file', 'repeater') else False if k == 'bool' else ''


def is_empty(f, v):
    if f['type'] == 'hidden':
        return str(v if v is not None else '') == ''
    if isinstance(v, list):
        return len(v) == 0
    if isinstance(v, bool):
        return not v
    return v is None or str(v) == ''


def numeric(f, v):
    k = kind(f['type'])
    if k == 'number':
        return 0.0 if v in (None, '') else float(v)
    if k == 'bool':
        return 1.0 if v else 0.0
    if k in ('multi', 'file', 'repeater'):
        return float(len(v or []))
    if k == 'choice':
        for o in f['options']:
            if o['id'] == v:
                return float(o['value']) if is_numeric(o['value']) else 0.0
        return 0.0
    if k in ('text', 'hidden'):
        s = str(v if v is not None else '').strip()
        return float(s) if is_numeric(s) else 0.0
    return 0.0


def chars(f, v):
    if kind(f['type']) not in ('text', 'hidden'):
        return 0
    s = str(v if v is not None else '')
    if not f.get('count_space'):
        s = re.sub(r'[ \t\n\r\f\v]+', '', s)
    return len(s)


def words(v):
    return len([w for w in re.split(r'[ \t\n\r\f\v]+', v.strip()) if w]) if isinstance(v, str) else 0


def lines_of(v):
    return len(v.replace('\r', '').strip().split('\n')) if isinstance(v, str) and v.strip() else 0


def to_str(v):
    if isinstance(v, list):
        return ''
    if isinstance(v, bool):
        return '1' if v else ''
    if isinstance(v, float):
        return ('%.6f' % v).rstrip('0').rstrip('.')
    return '' if v is None else str(v)


def test_rule(t, v, r):
    k = kind(t['type'])
    want = str(r['value'])
    op = r['op']
    if op == 'empty':
        return is_empty(t, v)
    if op == 'not_empty':
        return not is_empty(t, v)
    if op == 'checked':
        return bool(v) if k == 'bool' else not is_empty(t, v)
    if op == 'unchecked':
        return (not v) if k == 'bool' else is_empty(t, v)
    if op in ('is', 'is_not'):
        if k in ('choice', 'multi'):
            m = want in (v if isinstance(v, list) else [v])
        elif k == 'bool':
            m = bool(v) == (want.lower() in ('1', 'yes', 'true', 'on', 'checked'))
        else:
            have = to_str(v)
            m = abs(float(have) - float(want)) < 1e-9 if is_numeric(have) and is_numeric(want) else have.strip().lower() == want.strip().lower()
        return m if op == 'is' else not m
    if op in ('contains', 'not_contains'):
        if isinstance(v, list) or k == 'choice':
            m = want in (v if isinstance(v, list) else [v])
        else:
            m = want != '' and want.lower() in to_str(v).lower()
        return m if op == 'contains' else not m
    if op in ('gt', 'lt', 'gte', 'lte'):
        if not is_numeric(want) or is_empty(t, v):
            return False
        n = numeric(t, v)
        w = float(want)
        return {'gt': n > w, 'lt': n < w, 'gte': n >= w, 'lte': n <= w}[op]
    return False


def parents(fields):
    out, section, step = {}, None, None
    for f in fields:
        if f['type'] == 'step':
            step, section = f['id'], None
            out[f['id']] = []
        elif f['type'] == 'section':
            section = f['id']
            out[f['id']] = [step] if step else []
        else:
            out[f['id']] = [p for p in (step, section) if p]
    return out


def visibility(fields, values):
    by_id = {f['id']: f for f in fields}
    vis = {f['id']: True for f in fields}
    par = parents(fields)
    for _ in range(len(fields) + 2):
        changed = False
        for f in fields:
            c = f['conditions']
            v = True
            if c['enabled'] and c['rules']:
                res = []
                for r in c['rules']:
                    t = by_id.get(r['field'])
                    if not t:
                        res.append(False)
                        continue
                    val = values[t['id']] if vis[t['id']] and t['id'] in values else empty_value(t)
                    res.append(test_rule(t, val, r))
                ok = (True in res) if c['match'] == 'any' else (False not in res)
                v = (not ok) if c['action'] == 'hide' else ok
            for p in par[f['id']]:
                if not vis[p]:
                    v = False
            if v != vis[f['id']]:
                vis[f['id']] = v
                changed = True
        if not changed:
            break
    return vis


def own_vars(f, v, shared):
    d = dict(shared)
    d.update({'value': numeric(f, v), 'len': chars(f, v), 'words': words(v), 'lines': lines_of(v),
              'count': len(v) if isinstance(v, list) else (0 if is_empty(f, v) else 1)})
    return d


def amount(f, v, base, qty, shared, formulas):
    if is_empty(f, v):
        return 0.0
    k = kind(f['type'])
    a = 0.0
    if k in ('choice', 'multi'):
        sel = v if isinstance(v, list) else [v]
        for o in f['options']:
            if o['id'] in sel:
                a += base * o['price'] / 100 if o['price_type'] == 'percent' else o['price']
    if k == 'repeater':
        for row in v:
            for s in f['subfields']:
                if kind(s['type']) != 'layout':
                    sv = row.get(s['id'], empty_value(s))
                    a += amount(s, sv, base, qty, own_vars(s, sv, shared), formulas)
    p = f['price']
    pt = f['price_type']
    if pt == 'flat':
        a += p * len(v) if k == 'repeater' else p
    elif pt == 'percent':
        a += base * p / 100
    elif pt == 'per_char':
        a += p * max(0, chars(f, v) - f['free_chars'])
    elif pt == 'per_unit':
        a += p * numeric(f, v)
    elif pt == 'qty_tiers':
        tp = 0.0
        for t in f['tiers']:
            if qty >= t['min']:
                tp = t['price']
        a += tp
    elif pt == 'formula':
        a += formulas[f['id']](own_vars(f, v, shared))
    return a


def round_half(x, dp=2):
    x = x + (1e-9 if x >= 0 else -1e-9)
    m = 10 ** dp
    return math.copysign(math.floor(abs(x) * m + 0.5) / m, x)


def calc(fields, values, base, qty, formulas):
    vis = visibility(fields, values)
    shared = {'price': base, 'qty': qty}
    for f in fields:
        if kind(f['type']) == 'layout':
            continue
        v = values[f['id']] if vis[f['id']] and f['id'] in values else empty_value(f)
        shared[f['key']] = numeric(f, v)
        shared[f['key'] + '.len'] = chars(f, v)
        shared[f['key'] + '.count'] = len(v) if isinstance(v, list) else (0 if is_empty(f, v) else 1)
    unit = once = 0.0
    for f in fields:
        if kind(f['type']) == 'layout' or not vis[f['id']]:
            continue
        a = amount(f, values.get(f['id'], empty_value(f)), base, qty, shared, formulas)
        if a == 0:
            continue
        if f['charge'] == 'once':
            once += a
        else:
            unit += a
    total = round_half(max(0.0, base + unit) * qty + once)
    return round(unit, 6), round(once, 6), total


def case(name, fields, values, base=20.0, qty=1, formulas=None):
    formulas = formulas or {}
    unit, once, total = calc(fields, values, base, qty, formulas)
    CASES.append({'name': name, 'fields': fields, 'values': values, 'base': base, 'qty': qty,
                  'expect': {'unit': unit, 'once': once, 'total': total}})


# ------------------------------------------------------------------ cases

# Flat / percent on text, number, bool, date, color, hidden.
case('flat text filled', [F('fa', 'text', price_type='flat', price=3)], {'fa': 'Hi'})
case('flat text empty', [F('fa', 'text', price_type='flat', price=3)], {'fa': ''})
case('flat textarea qty 3', [F('fa', 'textarea', price_type='flat', price=2.5)], {'fa': 'Note'}, qty=3)
case('percent text', [F('fa', 'text', price_type='percent', price=10)], {'fa': 'x'}, base=45)
case('percent negative', [F('fa', 'text', price_type='percent', price=-15)], {'fa': 'x'}, base=40)
case('flat checkbox checked', [F('fa', 'checkbox', price_type='flat', price=4.99)], {'fa': True})
case('flat checkbox unchecked', [F('fa', 'checkbox', price_type='flat', price=4.99)], {'fa': False})
case('toggle percent', [F('fa', 'toggle', price_type='percent', price=12.5)], {'fa': True}, base=80)
case('date flat', [F('fa', 'date', price_type='flat', price=7)], {'fa': '2026-12-24'})
case('date empty', [F('fa', 'date', price_type='flat', price=7)], {'fa': ''})
case('color flat', [F('fa', 'color', price_type='flat', price=1.25)], {'fa': '#ff0000'})
case('hidden flat always', [F('fa', 'hidden', price_type='flat', price=2, default='setup')], {'fa': 'setup'})
case('hidden empty default', [F('fa', 'hidden', price_type='flat', price=2, default='')], {'fa': ''})
case('email flat', [F('fa', 'email', price_type='flat', price=0.5)], {'fa': 'a@b.co'}, qty=4)
case('number flat when set', [F('fa', 'number', price_type='flat', price=5)], {'fa': 0})
case('number flat when null', [F('fa', 'number', price_type='flat', price=5)], {'fa': None})

# Per character.
case('per char basic', [F('fa', 'text', price_type='per_char', price=0.5)], {'fa': 'HELLO'})
case('per char spaces ignored', [F('fa', 'text', price_type='per_char', price=1)], {'fa': 'A B C'})
case('per char spaces counted', [F('fa', 'text', price_type='per_char', price=1, count_space=True)], {'fa': 'A B C'})
case('per char free 10 under', [F('fa', 'text', price_type='per_char', price=2, free_chars=10)], {'fa': 'abcdefgh'})
case('per char free 10 over', [F('fa', 'text', price_type='per_char', price=2, free_chars=10)], {'fa': 'abcdefghijklmn'})
case('per char unicode', [F('fa', 'text', price_type='per_char', price=1)], {'fa': 'Zoë 💙'})
case('per char textarea newlines', [F('fa', 'textarea', price_type='per_char', price=0.1)], {'fa': 'ab\ncd\n\nef'})
case('per char qty 2', [F('fa', 'text', price_type='per_char', price=0.25)], {'fa': 'Engrave'}, qty=2)
case('per char cents', [F('fa', 'text', price_type='per_char', price=0.333)], {'fa': 'abc'})

# Per unit / numbers.
case('per unit number', [F('fa', 'number', price_type='per_unit', price=1.5)], {'fa': 4})
case('per unit decimal', [F('fa', 'number', price_type='per_unit', price=2)], {'fa': 2.75})
case('per unit range', [F('fa', 'range', price_type='per_unit', price=0.2, min=0, max=100)], {'fa': 35})
case('per unit zero', [F('fa', 'number', price_type='per_unit', price=3)], {'fa': 0})
case('per unit negative price', [F('fa', 'number', price_type='per_unit', price=-1)], {'fa': 3})
case('per unit file count', [F('fa', 'file', price_type='per_unit', price=2)], {'fa': [{'token': 'a', 'name': 'a.png'}, {'token': 'b', 'name': 'b.png'}]})
case('flat file', [F('fa', 'file', price_type='flat', price=6)], {'fa': [{'token': 'a', 'name': 'a.png'}]})

# Quantity tiers.
T = [{'min': 1, 'price': 5}, {'min': 10, 'price': 4}, {'min': 50, 'price': 3}]
case('tiers qty 1', [F('fa', 'checkbox', price_type='qty_tiers', tiers=T)], {'fa': True}, qty=1)
case('tiers qty 9', [F('fa', 'checkbox', price_type='qty_tiers', tiers=T)], {'fa': True}, qty=9)
case('tiers qty 10', [F('fa', 'checkbox', price_type='qty_tiers', tiers=T)], {'fa': True}, qty=10)
case('tiers qty 75', [F('fa', 'checkbox', price_type='qty_tiers', tiers=T)], {'fa': True}, qty=75)
case('tiers below first', [F('fa', 'text', price_type='qty_tiers', tiers=[{'min': 5, 'price': 2}])], {'fa': 'x'}, qty=3)
case('tiers unchecked', [F('fa', 'checkbox', price_type='qty_tiers', tiers=T)], {'fa': False}, qty=20)

# Choices.
SIZES = [O('os', 'Small', 0), O('om', 'Medium', 3), O('ol', 'Large', 6.5)]
case('select free', [F('fa', 'select', options=SIZES)], {'fa': 'os'})
case('select priced', [F('fa', 'select', options=SIZES)], {'fa': 'ol'})
case('select none', [F('fa', 'select', options=SIZES)], {'fa': ''})
case('radio percent option', [F('fa', 'radio', options=[O('oa', 'Gold', 20, 'percent'), O('ob', 'Silver', 5, 'percent')])], {'fa': 'oa'}, base=150)
case('swatch flat', [F('fa', 'color_swatch', options=[O('oa', 'Red', 1), O('ob', 'Blue', 2)])], {'fa': 'ob'}, qty=5)
case('image picker', [F('fa', 'image_picker', options=[O('oa', 'Classic', 0), O('ob', 'Modern', 12)])], {'fa': 'ob'})
case('checkboxes sum', [F('fa', 'checkboxes', options=[O('oa', 'Wrap', 3), O('ob', 'Card', 1.5), O('oc', 'Box', 4)])], {'fa': ['oa', 'oc']})
case('checkboxes all', [F('fa', 'checkboxes', options=[O('oa', 'Wrap', 3), O('ob', 'Card', 1.5), O('oc', 'Box', 4)])], {'fa': ['oa', 'ob', 'oc']}, qty=2)
case('checkboxes mixed percent', [F('fa', 'checkboxes', options=[O('oa', 'Insure', 10, 'percent'), O('ob', 'Card', 2)])], {'fa': ['oa', 'ob']}, base=60)
case('checkboxes empty', [F('fa', 'checkboxes', options=[O('oa', 'Wrap', 3)])], {'fa': []})
case('discount option floors at zero', [F('fa', 'select', options=[O('oa', 'Voucher', -50)])], {'fa': 'oa'}, base=20)
case('choice plus formula', [F('fa', 'select', options=SIZES, price_type='formula', formula='{qty} * 0.5')], {'fa': 'om'}, qty=4,
     formulas={'fa': lambda v: v['qty'] * 0.5})

# Charge once.
case('once flat', [F('fa', 'text', price_type='flat', price=15, charge='once')], {'fa': 'Logo'}, qty=10)
case('once and item', [F('fa', 'text', price_type='flat', price=15, charge='once'), F('fb', 'checkbox', price_type='flat', price=2)], {'fa': 'Logo', 'fb': True}, qty=3)
case('once percent', [F('fa', 'checkbox', price_type='percent', price=50, charge='once')], {'fa': True}, base=30, qty=4)
case('once option', [F('fa', 'select', options=[O('oa', 'Setup', 25)], charge='once')], {'fa': 'oa'}, qty=7)

# Formulas.
def fcase(name, formula, fn, ftype='text', value='', base=20.0, qty=1, **kw):
    case(name, [F('fa', ftype, price_type='formula', formula=formula, **kw)], {'fa': value}, base=base, qty=qty, formulas={'fa': fn})


fcase('formula letters over 10 (short)', 'max(0, {len} - 10) * 2', lambda v: max(0, v['len'] - 10) * 2, value='HELLO')
fcase('formula letters over 10 (long)', 'max(0, {len} - 10) * 2', lambda v: max(0, v['len'] - 10) * 2, value='Happy Birthday Anna')
fcase('formula precedence', '2 + 3 * 4', lambda v: 2 + 3 * 4, value='x')
fcase('formula parentheses', '(2 + 3) * 4', lambda v: (2 + 3) * 4, value='x')
fcase('formula power right assoc', '2 ^ 3 ^ 2 / 100', lambda v: 2 ** (3 ** 2) / 100, value='x')
fcase('formula power', '2 ^ 3 + 1', lambda v: 2 ** 3 + 1, value='x')
fcase('formula unary minus power', '10 - -2 ^ 2', lambda v: 10 - (-(2 ** 2)), value='x')
fcase('formula division', '{price} / 4', lambda v: v['price'] / 4, value='x', base=19)
fcase('formula division by zero', '5 / 0 + 1', lambda v: 0 + 1, value='x')
fcase('formula modulo', '17 % 5', lambda v: 17 % 5, value='x')
fcase('formula if true', 'if({len} > 5, 10, 2)', lambda v: 10 if v['len'] > 5 else 2, value='abcdefg')
fcase('formula if false', 'if({len} > 5, 10, 2)', lambda v: 10 if v['len'] > 5 else 2, value='abc')
fcase('formula round 2', 'round({price} * 0.333, 2)', lambda v: round_half(v['price'] * 0.333 - 1e-9, 2), value='x', base=10)
fcase('formula round half up', 'round(2.5)', lambda v: 3, value='x')
fcase('formula round negative half', 'round(-2.5) + 10', lambda v: -3 + 10, value='x')
fcase('formula ceil words', 'ceil({words} / 3) * 4', lambda v: math.ceil(v['words'] / 3) * 4, value='one two three four five')
fcase('formula floor', 'floor({value} / 10) * 3', lambda v: math.floor(v['value'] / 10) * 3, ftype='number', value=47)
fcase('formula area', '{value} * {price} * 0.01', lambda v: v['value'] * v['price'] * 0.01, ftype='number', value=120, base=30)
fcase('formula sqrt', 'sqrt({value}) * 2', lambda v: math.sqrt(v['value']) * 2, ftype='number', value=16)
fcase('formula sqrt negative', 'sqrt(-4) + 1', lambda v: 1, value='x')
fcase('formula clamp', 'clamp({value} * 2, 5, 30)', lambda v: max(5, min(30, v['value'] * 2)), ftype='number', value=40)
fcase('formula min max', 'min(10, max(2, {len}))', lambda v: min(10, max(2, v['len'])), value='abcdef')
fcase('formula and or', '({len} > 2 && {len} < 5) * 7 + ({len} == 0 || {len} > 9) * 3', lambda v: (1 if 2 < v['len'] < 5 else 0) * 7 + (1 if v['len'] == 0 or v['len'] > 9 else 0) * 3, value='abc')
fcase('formula not', '!0 * 4 + !5 * 9', lambda v: 4, value='x')
fcase('formula lines', '{lines} * 1.5', lambda v: v['lines'] * 1.5, ftype='textarea', value='a\nb\nc')
fcase('formula qty discount', 'if({qty} >= 10, 1, 2)', lambda v: 1 if v['qty'] >= 10 else 2, value='x', qty=12)
fcase('formula comparison chain', '({value} >= 5) + ({value} <= 5) + ({value} != 5)', lambda v: (v['value'] >= 5) + (v['value'] <= 5) + (v['value'] != 5), ftype='number', value=5)
fcase('formula pow fn', 'pow(1.1, 2) * 100', lambda v: (1.1 ** 2) * 100, value='x')
fcase('formula abs', 'abs({value} - 10)', lambda v: abs(v['value'] - 10), ftype='number', value=3)
fcase('formula invalid gives zero', 'max(0, {len} -', lambda v: 0, value='abc')
fcase('formula unknown var zero', '{nope} * 5 + 1', lambda v: 1, value='abc')
fcase('formula scientific', '1.5e1 + .5', lambda v: 15.5, value='x')
fcase('formula checkbox value', '{value} * 3 + {count}', lambda v: v['value'] * 3 + v['count'], ftype='checkbox', value=True)
fcase('formula empty field skipped', '100', lambda v: 100, value='')

# Field references.
W = F('fw', 'number', key='width')
H = F('fh', 'number', key='height')
case('formula field refs', [W, H, F('fa', 'hidden', default='1', price_type='formula', formula='{width} * {height} * 0.05')],
     {'fw': 30, 'fh': 40, 'fa': '1'}, formulas={'fa': lambda v: v['width'] * v['height'] * 0.05})
case('formula field ref missing value', [W, H, F('fa', 'hidden', default='1', price_type='formula', formula='{width} * {height} * 0.05')],
     {'fw': 30, 'fh': None, 'fa': '1'}, formulas={'fa': lambda v: v['width'] * v['height'] * 0.05})
case('formula ref len', [F('fn', 'text', key='name'), F('fa', 'checkbox', price_type='formula', formula='{name.len} * 0.2')],
     {'fn': 'Alexandra', 'fa': True}, formulas={'fa': lambda v: v['name.len'] * 0.2})
case('formula ref count', [F('fx', 'checkboxes', key='extras', options=[O('oa', 'A'), O('ob', 'B'), O('oc', 'C')]), F('fa', 'checkbox', price_type='formula', formula='{extras.count} * 1.25')],
     {'fx': ['oa', 'oc'], 'fa': True}, formulas={'fa': lambda v: v['extras.count'] * 1.25})
case('formula ref numeric option', [F('fp', 'select', key='pack', options=[O('o6', '6 pack', 0, value='6'), O('o12', '12 pack', 0, value='12')]), F('fa', 'checkbox', price_type='formula', formula='{pack} * 0.75')],
     {'fp': 'o12', 'fa': True}, formulas={'fa': lambda v: v['pack'] * 0.75})
case('formula ref hidden by condition', [F('fg', 'checkbox', key='gift'), F('fm', 'text', key='message', conditions=cond([('fg', 'checked')])), F('fa', 'hidden', default='1', price_type='formula', formula='{message.len} * 0.1')],
     {'fg': False, 'fm': 'Hidden text', 'fa': '1'}, formulas={'fa': lambda v: v['message.len'] * 0.1})

# Conditions.
G = F('fg', 'checkbox', key='gift', price_type='flat', price=2)
MSG = F('fm', 'text', key='message', price_type='per_char', price=0.1, conditions=cond([('fg', 'checked')]))
case('cond shown', [G, MSG], {'fg': True, 'fm': 'Love you'})
case('cond hidden ignores value', [G, MSG], {'fg': False, 'fm': 'Love you'})
case('cond hide action', [G, F('fm', 'text', price_type='flat', price=5, conditions=cond([('fg', 'checked')], action='hide'))], {'fg': True, 'fm': 'x'})
case('cond hide action shown', [G, F('fm', 'text', price_type='flat', price=5, conditions=cond([('fg', 'checked')], action='hide'))], {'fg': False, 'fm': 'x'})
SZ = F('fs', 'select', key='size', options=SIZES)
case('cond select is', [SZ, F('fx', 'checkbox', price_type='flat', price=4, conditions=cond([('fs', 'is', 'ol')]))], {'fs': 'ol', 'fx': True})
case('cond select is not met', [SZ, F('fx', 'checkbox', price_type='flat', price=4, conditions=cond([('fs', 'is', 'ol')]))], {'fs': 'om', 'fx': True})
case('cond any match', [SZ, G, F('fx', 'checkbox', price_type='flat', price=4, conditions=cond([('fs', 'is', 'ol'), ('fg', 'checked')], match='any'))], {'fs': 'os', 'fg': True, 'fx': True})
case('cond all match fails', [SZ, G, F('fx', 'checkbox', price_type='flat', price=4, conditions=cond([('fs', 'is', 'ol'), ('fg', 'checked')]))], {'fs': 'os', 'fg': True, 'fx': True})
case('cond number gt', [F('fq', 'number', key='qty_pick'), F('fx', 'checkbox', price_type='flat', price=9, conditions=cond([('fq', 'gt', '10')]))], {'fq': 11, 'fx': True})
case('cond number gt boundary', [F('fq', 'number', key='qty_pick'), F('fx', 'checkbox', price_type='flat', price=9, conditions=cond([('fq', 'gt', '10')]))], {'fq': 10, 'fx': True})
case('cond text contains', [F('ft', 'text', key='note'), F('fx', 'checkbox', price_type='flat', price=3, conditions=cond([('ft', 'contains', 'GOLD')]))], {'ft': 'make it gold please', 'fx': True})
case('cond empty', [F('ft', 'text', key='note'), F('fx', 'checkbox', price_type='flat', price=3, conditions=cond([('ft', 'empty')]))], {'ft': '', 'fx': True})
case('cond chain hidden parent', [G, MSG, F('fz', 'checkbox', price_type='flat', price=1, conditions=cond([('fm', 'not_empty')]))], {'fg': False, 'fm': 'still here', 'fz': True})
case('cond multi contains', [F('fx', 'checkboxes', options=[O('oa', 'A', 1), O('ob', 'B', 1)]), F('fy', 'checkbox', price_type='flat', price=5, conditions=cond([('fx', 'contains', 'ob')]))], {'fx': ['oa', 'ob'], 'fy': True})
case('cond missing target hides', [F('fy', 'checkbox', price_type='flat', price=5, conditions=cond([('f_gone', 'checked')]))], {'fy': True})
case('cond loop settles', [F('fa', 'checkbox', price_type='flat', price=1, conditions=cond([('fb', 'checked')])), F('fb', 'checkbox', price_type='flat', price=2, conditions=cond([('fa', 'checked')]))], {'fa': True, 'fb': True})
case('cond numeric text is', [F('ft', 'text', key='code'), F('fx', 'checkbox', price_type='flat', price=2, conditions=cond([('ft', 'is', '7.0')]))], {'ft': '7', 'fx': True})

# Sections and steps.
case('section hidden hides children', [G, F('sa', 'section', conditions=cond([('fg', 'checked')])), F('fx', 'text', price_type='flat', price=8)], {'fg': False, 'fx': 'x'})
case('section shown', [G, F('sa', 'section', conditions=cond([('fg', 'checked')])), F('fx', 'text', price_type='flat', price=8)], {'fg': True, 'fx': 'x'})
case('step hidden hides section and field', [F('s1', 'step'), G, F('s2', 'step', conditions=cond([('fg', 'checked')])), F('sa', 'section'), F('fx', 'text', price_type='flat', price=8)], {'fg': False, 'fx': 'x'})
case('new step resets section', [G, F('sa', 'section', conditions=cond([('fg', 'checked')])), F('fx', 'text', price_type='flat', price=8), F('s2', 'step'), F('fy', 'text', price_type='flat', price=1)], {'fg': False, 'fx': 'x', 'fy': 'y'})

# Repeaters.
NAME = F('rn', 'text', key='name', price_type='per_char', price=0.5)
SIZE = F('rs', 'select', key='size', options=[O('ro1', 'S', 0), O('ro2', 'XL', 3)])
case('repeater rows', [F('fr', 'repeater', subfields=[NAME, SIZE])], {'fr': [{'rn': 'Ann', 'rs': 'ro1'}, {'rn': 'Bo', 'rs': 'ro2'}]})
case('repeater flat per row', [F('fr', 'repeater', subfields=[NAME], price_type='flat', price=4)], {'fr': [{'rn': 'A'}, {'rn': ''}, {'rn': 'CC'}]})
case('repeater empty', [F('fr', 'repeater', subfields=[NAME], price_type='flat', price=4)], {'fr': []})
case('repeater qty 2 once', [F('fr', 'repeater', subfields=[NAME], charge='once')], {'fr': [{'rn': 'Abcd'}]}, qty=2)
case('repeater formula rows', [F('fr', 'repeater', subfields=[NAME], price_type='formula', formula='{count} * 1.1')], {'fr': [{'rn': 'A'}, {'rn': 'B'}, {'rn': 'C'}]},
     formulas={'fr': lambda v: v['count'] * 1.1})

# Combined / rounding.
case('combined product', [F('fe', 'text', price_type='per_char', price=0.75, free_chars=3), F('fw', 'checkbox', price_type='flat', price=3.5), F('fz', 'select', options=SIZES), F('fs', 'text', price_type='flat', price=10, charge='once')],
     {'fe': 'Max Power', 'fw': True, 'fz': 'om', 'fs': 'logo'}, base=24.99, qty=3)
case('rounding half cent', [F('fa', 'text', price_type='flat', price=0.005)], {'fa': 'x'}, base=1)
case('rounding thirds', [F('fa', 'text', price_type='percent', price=33.3333)], {'fa': 'x'}, base=9.99, qty=3)
case('large quantity', [F('fa', 'checkbox', price_type='flat', price=0.07)], {'fa': True}, base=1.13, qty=999)
case('zero base percent', [F('fa', 'checkbox', price_type='percent', price=50)], {'fa': True}, base=0)
case('no fields', [], {}, base=12.34, qty=2)
case('layout only', [F('pa', 'paragraph'), F('sa', 'section')], {}, base=5)

CASES = [c for c in CASES if c]
assert len(CASES) >= 100, len(CASES)
out = os.path.join(os.path.dirname(os.path.abspath(__file__)), 'price-cases.json')
with open(out, 'w') as fh:
    json.dump(CASES, fh, indent=1, ensure_ascii=False)
print(f'{len(CASES)} cases -> {out}')
