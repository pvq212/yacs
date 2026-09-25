#!/usr/bin/env python3
"""Validate this design package. Requires PyYAML and jsonschema; not application tests."""
from pathlib import Path
import json,re,sys
import yaml
from jsonschema import Draft202012Validator,FormatChecker
ROOT=Path(__file__).resolve().parents[1]
def check(ok,message):
    if not ok:raise AssertionError(message)
    print('PASS',message)
def local_pointer(doc,pointer):
    value=doc
    for part in pointer[2:].split('/'):
        value=value[part.replace('~1','/').replace('~0','~')]
    return value
def walk(v):
    yield v
    if isinstance(v,dict):
        for val in v.values():yield from walk(val)
    elif isinstance(v,list):
        for val in v:yield from walk(val)
def main():
    d=yaml.safe_load((ROOT/'contracts/openapi.yaml').read_text())
    check(d==json.loads((ROOT/'contracts/openapi.json').read_text()),'OpenAPI YAML and JSON are equal')
    check(d['openapi']=='3.1.0','OpenAPI design targets version 3.1.0')
    refs=[x['$ref'] for x in walk(d) if isinstance(x,dict) and '$ref' in x]
    for r in refs:
        check_local=r.startswith('#/')
        if not check_local:raise AssertionError('Unexpected external OpenAPI reference: '+r)
        local_pointer(d,r)
    check(True,f'{len(refs)} local OpenAPI references resolve')
    ids=[];count=0
    for path,item in d['paths'].items():
        for method,op in item.items():
            check(method in ['get','post','put','patch','delete','head','options','trace'],f'HTTP method valid: {method.upper()} {path}')
            ids.append(op['operationId']);count+=1
            pars=[local_pointer(d,p['$ref']) if '$ref' in p else p for p in op.get('parameters',[])]
            pathpars={p['name'] for p in pars if p['in']=='path'}
            if pathpars!=set(re.findall(r'\{([^}]+)\}',path)):raise AssertionError('Path parameters mismatch: '+path)
            if len({(p['in'],p['name']) for p in pars})!=len(pars):raise AssertionError('Duplicate parameters: '+path)
            for p in pars:
                if p['in']=='path' and not p.get('required'):raise AssertionError('Path parameter must be required')
            for scheme in op.get('security',[]):
                for key in scheme:
                    if key not in d['components']['securitySchemes']:raise AssertionError('Unknown auth '+key)
    check(len(ids)==len(set(ids)),f'{count} unique operationIds and valid path parameters/security references')
    for name,schema in d['components']['schemas'].items():Draft202012Validator.check_schema(schema)
    check(True,f'{len(d["components"]["schemas"])} component schemas pass JSON Schema meta-validation')
    # Audience-specific schemas are self-contained. Validate actual sample + a negative leakage case.
    for name in ['public','staff']:
        schema=json.loads((ROOT/f'contracts/{name}-realtime-event.schema.json').read_text())
        Draft202012Validator.check_schema(schema)
    pub=json.loads((ROOT/'contracts/public-realtime-event.schema.json').read_text())
    validator=Draft202012Validator(pub,format_checker=FormatChecker())
    sample=json.loads((ROOT/'examples/public-message-created.json').read_text())
    validator.validate(sample)
    check(True,'Public event example validates against the public event schema')
    bad=json.loads(json.dumps(sample));bad['data']['message']['visibility']='internal'
    check(not validator.is_valid(bad),'Public event schema rejects staff-only visibility field')
    bad=json.loads(json.dumps(sample));bad['event_seq']=42
    check(not validator.is_valid(bad),'Event sequence rejects JSON numbers to preserve bigint precision')
    config=yaml.safe_load((ROOT/'CONFIG_DEFAULTS.yaml').read_text())
    for k in ['core','ai','knowledge']:
        q=config['queues'][k]
        check(q['job_timeout_seconds']<q['supervisor_timeout_seconds']<q['retry_after_seconds'],f'{k}: job timeout < supervisor timeout < retry_after')
    tests=yaml.safe_load((ROOT/'acceptance-tests.yaml').read_text())
    tid=[t['id'] for t in tests['tests']]
    check(len(tid)==85 and len(tid)==len(set(tid)),'85 unique acceptance requirement IDs')
    md=(ROOT/'ACCEPTANCE_TESTS.md').read_text()
    check(all(t['id'] in md for t in tests['tests']),'Every acceptance ID is present in the human-readable document')
    reference_text=(ROOT/'REFERENCES.md').read_text()
    used=set()
    for p in ROOT.glob('*.md'):used.update(re.findall(r'\[(R\d+)\]',p.read_text()))
    check(all(f'| {r} |' in reference_text for r in used),'All Rxx references used in prose are defined')
    check(not any('\ue200' in p.read_text() for p in ROOT.rglob('*.md')),'No UI-only citation markers leaked into Markdown artifacts')
    print(f'\nRESULT: design checks passed ({len(d["paths"])} paths, {count} operations, {len(tid)} acceptance requirements).')
    print('NOT RUN: complete OAS semantic-validator suite, Laravel/runtime tests, provider live calls, browser SDK implementation tests, load or restore drills.')
if __name__=='__main__':main()
