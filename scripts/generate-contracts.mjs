import fs from 'node:fs';
const doc=JSON.parse(fs.readFileSync('docs/spec/contracts/openapi.json','utf8'));
const resolve=x=>Array.isArray(x)?x.map(resolve):x&&typeof x==='object'?('$ref' in x?resolve(x.$ref.split('/').slice(1).reduce((a,k)=>a[k],doc)):Object.fromEntries(Object.entries(x).map(([k,v])=>[k,resolve(v)]))):x;
fs.writeFileSync('resources/js/admin-schemas.json',JSON.stringify(doc.components.schemas,null,2)+'\n');
