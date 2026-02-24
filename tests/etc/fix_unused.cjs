const fs = require('fs');

const issues = fs.readFileSync('frontend_type_issues.md', 'utf8');
const lines = issues.split('\n');

const unusedRegex = /(resources\/js\/.*?\.(?:vue|ts))\((\d+),\d+\): error TS6133: '(.*?)' is declared/;
const neverUsedRegex = /(resources\/js\/.*?\.(?:vue|ts))\((\d+),\d+\): error TS6196: '(.*?)' is declared but never used/;
const allUnusedRegex = /(resources\/js\/.*?\.(?:vue|ts))\((\d+),\d+\): error TS6192: All imports in import declaration are unused./;

const modifications = {};
let count = 0;

lines.forEach(line => {
    let match = line.match(unusedRegex) || line.match(neverUsedRegex);
    if (match) {
        const file = match[1];
        const lineNum = parseInt(match[2], 10) - 1; 
        const varName = match[3];
        
        if (!modifications[file]) modifications[file] = [];
        modifications[file].push({ type: 'var', lineNum, varName });
        count++;
    }
    
    let matchAll = line.match(allUnusedRegex);
    if (matchAll) {
        const file = matchAll[1];
        const lineNum = parseInt(matchAll[2], 10) - 1;
        
        if (!modifications[file]) modifications[file] = [];
        modifications[file].push({ type: 'all', lineNum });
        count++;
    }
});

for (const file of Object.keys(modifications)) {
    if (!fs.existsSync(file)) {
        console.log(`File not found: ${file}`);
        continue;
    }
    
    let contentLines = fs.readFileSync(file, 'utf8').split('\n');
    // Sort descending by lineNum to edit from bottom up (prevents line shifts if we delete lines, though we are doing in-place replacement mostly)
    const mods = modifications[file].sort((a, b) => b.lineNum - a.lineNum); 
    
    mods.forEach(mod => {
        if (mod.lineNum >= contentLines.length) return;
        
        let line = contentLines[mod.lineNum];
        let originalLine = line;
        
        if (mod.type === 'all') {
            line = '// ' + line;
        } else if (mod.type === 'var') {
            // Regexes to carefully strip out the unused variable references
            const regex1 = new RegExp(`\\b${mod.varName}\\s*,\\s*`); // 'Var, '
            const regex2 = new RegExp(`,\\s*\\b${mod.varName}\\b`); // ', Var'
            const regex3 = new RegExp(`\\{\\s*\\b${mod.varName}\\b\\s*\\}`); // '{ Var }'
            const regexImportFrom = new RegExp(`import\\s+\\b${mod.varName}\\b\\s+from`);
            const regexConst = new RegExp(`const\\s+\\b${mod.varName}\\b`);
            const regexLet = new RegExp(`let\\s+\\b${mod.varName}\\b`);

            if (regex1.test(line)) {
                line = line.replace(regex1, '');
            } else if (regex2.test(line)) {
                line = line.replace(regex2, '');
            } else if (regex3.test(line)) {
                line = line.replace(regex3, '{}');
            } else if (regexImportFrom.test(line)) {
                line = '// ' + line;
            } else if (regexConst.test(line) || regexLet.test(line)) {
                line = '// ' + line;
            }
            
            // Clean up empty imports
            if (line.includes('import {} from') || line.includes('import { } from')) {
                line = '// ' + line;
            }
        }
        
        contentLines[mod.lineNum] = line;
    });
    
    fs.writeFileSync(file, contentLines.join('\n'));
}

console.log(`Processed ${count} unused variables.`);
