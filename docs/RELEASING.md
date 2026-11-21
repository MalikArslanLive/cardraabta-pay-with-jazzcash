# Building and releasing CardRaabta

Build from a clean, reviewed source tree. The WordPress plugin header, readme Stable tag, runtime version constant and release manifest must agree. The installable archive includes runtime assets, library code, the WordPress readme, LICENSE and NOTICE. GitHub documentation, fixtures, tests and build scripts are kept in source.

## Verify the candidate

1. Review [WordPress.org detailed plugin guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/) and the [readme format](https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/).
2. Confirm distribution rights for every bundled asset. Keep the license and any notices with the source and archive.
3. Use a disposable test store. Run the checks described in [Testing](TESTING.md), including Plugin Check against the extracted package. Record the exact outcomes in [Verification](VERIFICATION.md).
4. Ensure the ZIP excludes credentials, private reports, development files and nested archives. Check the external-service declarations against actual endpoints. Record any unverified merchant-side behavior.

```sh
python3 scripts/build-release.py releases/1.0.0
python3 scripts/verify-release.py releases/1.0.0/cardraabta-pay-with-jazzcash-1.0.0.zip
```

The build sets fixed metadata for reproducibility and creates an external SHA-256 sidecar. Verification checks ZIP contents, permissions, versions and source bytes. After committing the package and exact source together, rerun with `--git-ref HEAD` to verify the committed copy. At publication, verify the downloaded bytes and checksum again.

## Publish

Keep the package and checksum in the version directory. Point the GitHub release and download links at the same verified archive. Retain the matched source commit, documentation and test results. Use an annotated release tag when publishing a new version. Version increments are normally required for standard WordPress update detection. Site owners replacing a same-version build should use upload-and-replace and clear affected caches.

WordPress.org publishes the plugin after its separate review. Submit the installable ZIP and use the approved release process for later updates. Check the directory readme's Stable tag, plugin headers and independently verified installation after submission.
