# CTT Drupal Module (Embedded Workflow Editor)

This module embeds the React CTT workflow editor inside Drupal.

## Required Frontend Build

The module does **not** compile React assets automatically.
After each frontend change in the CTT project, you must rebuild and copy files manually.

Source project:

- `c:\Users\BILIONAIRE\Documents\Graxiom\CTT`

Build command:

```bash
npm run build
```

Generated files:

- `dist/hasco-workflow-editor.umd.js`
- `dist/workflow-editor.css`

## Deploy Built Files to This Module

Copy both generated files into this module folder:

- `c:\xampp\htdocs\drupal\web\modules\custom\ctt\dist\`

Mapping:

- `CTT/dist/hasco-workflow-editor.umd.js` -> `modules/custom/ctt/dist/hasco-workflow-editor.umd.js`
- `CTT/dist/workflow-editor.css` -> `modules/custom/ctt/dist/workflow-editor.css`

PowerShell example:

```powershell
Copy-Item "c:\Users\BILIONAIRE\Documents\Graxiom\CTT\dist\hasco-workflow-editor.umd.js" "c:\xampp\htdocs\drupal\web\modules\custom\ctt\dist\hasco-workflow-editor.umd.js" -Force
Copy-Item "c:\Users\BILIONAIRE\Documents\Graxiom\CTT\dist\workflow-editor.css" "c:\xampp\htdocs\drupal\web\modules\custom\ctt\dist\workflow-editor.css" -Force
```

## Final Step

Clear Drupal cache after copying files (UI cache clear or `drush cr`).
If cache is not cleared, Drupal may keep serving an old aggregated JS/CSS bundle.
