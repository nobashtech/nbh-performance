# Publish the source on GitHub

The repository name can be `nbh-performance` within an account or organization you control. GitHub publishing and WordPress.org plugin submissions are separate processes.

1. Create an empty public GitHub repository (do not prepopulate it with a README or LICENSE).
2. Extract `nbh-performance-github-source-1.0.0.zip`, enter `nbh-performance/` and run:

```bash
git init
git add .
git commit -m "Prepare NBH Performance 1.0.0 for WordPress.org review"
git branch -M main
git remote add origin https://github.com/YOUR-ACCOUNT/nbh-performance.git
git push -u origin main
```

3. Replace `YOUR-ACCOUNT` with your actual GitHub user or organization. Run the GitHub Actions workflow and resolve failures before marking a release.
4. For WordPress.org, upload **only** `nbh-performance-wporg-1.0.0.zip` after real-site acceptance testing; do not upload the GitHub source ZIP.

These instructions do not mean any remote repository has been created or modified.
