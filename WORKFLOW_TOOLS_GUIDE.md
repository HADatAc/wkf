# Workflow Tools Guide (STD + CTT)

## Purpose
This guide explains where to find workflow tools and how to use them with real Study and Process context.

## Where To Access
You now have two access paths:

1. From Study management (recommended context path)
- Go to: Study Elements -> Manage Elements -> Manage Studies
- Open a Study
- In Associated Workflows, use the action buttons:
  - View Workflow
  - Tools Repository
  - R Analysis
  - Start Structured Submission (owner + permission required)

2. From main menu shortcuts
- Study Elements -> Manage Elements -> Workflow Tools Repository
- Study Elements -> Manage Elements -> Workflow R Analysis

## What Each Action Does

### View Workflow
- Opens the CTT editor directly:
  - /ctt/editor?studyUri={STUDY_URI}&processUri={PROCESS_URI}
- This is the stable path for workflow canvas visualization/editing.

### Start Structured Submission
- Opens structured submission with Study + Process context:
  - /ctt/submission/{base64StudyUri}?processUri={PROCESS_URI}
- Intended for execution/submission flow.

### Tools Repository
- Opens analytical tools catalog:
  - /workflow/tools-repository?studyUri={STUDY_URI}
- Use this to manage metadata for analytical tools and inspect associations.

### R Analysis
- Opens R analysis page prefilled with context:
  - /workflow/r-analysis?studyUri={STUDY_URI}&processUri={PROCESS_URI}
- Use Load Real Context, then run analysis with real backend calls.

## Required Permissions
- access ctt editor: required to open CTT editor canvas.
- submit ctt workflow: required for structured submission, tools repository, and R analysis workflows.
- create ctt workflow / edit ctt workflow: required for authoring/editing operations.

## Troubleshooting

### Symptom: "Connecting to API" does not finish
- Use the direct CTT route (/ctt/editor with processUri) instead of generic REP describe pages.
- Confirm user has access ctt editor.
- Confirm CTT API endpoints are reachable under:
  - /workflow/api/*
  - /workflow/hascoapi/api*

### Symptom: Access denied
- Check role permissions for:
  - access ctt editor
  - submit ctt workflow

### Symptom: New menu links not visible
- Clear Drupal cache:
  - drush cr

## Notes
- The tools in this flow are designed for real data paths (no mock API).
- Study URI and Process URI are carried by query parameters to keep context consistent across pages.
