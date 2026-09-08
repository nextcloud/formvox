# FormService refactor — plan of record

> **STATUS: COMPLETE.** The God-object `FormService` (2058 lines) and the
> God-controller `ApiController` (22 methods) are gone. The service layer is
> `FormRepository` (CRUD, 427 lines) + `FormFactory` + `FormFileLocator` +
> `FormLockManager` + `UploadService` + `OdtTemplateService` +
> `ShareTokenService` + `ResponsePersistenceService` + `IFormVersionCleaner`.
> Controllers: `FormController` / `ResponseController` / `ExportController` /
> `FormUploadController` (route URLs unchanged). Test harness stood up from
> scratch; **491 unit tests, 1196 assertions, green**, incl. a route-integrity
> guard. Branch `refactor/formservice-split`, 8 commits, nothing pushed.


Corrected after an adversarial design review (3 architects → jury → synthesis →
completeness critic + red-team). All the review's fixes are folded in below.

## Guiding principles (owner's, verified)

- **Kill the God-object.** `FormService` (2058 lines, 52 methods) is dismantled and
  deleted — no facade-preservation. Callers repoint to the new focused services.
- **Cohesive controllers, no God-controller.** The doctrine is *"every HTTP-facing
  use-case cluster gets one cohesive controller"* — **not** "every class gets a
  controller." The concurrency/access/construction cores (lock, locator, factory)
  are deliberately controller-less infrastructure; they are collaborators, not
  use-cases. `ApiController` (the God-controller mirroring the God-service) is split.
- **Pragmatic depth.** Real DI; inject the static `\OCP\Server::get()` calls.
  **Interfaces only where they buy something** — see the interface decision below.
- **Tests first, on stable seams.** The harness exists and is green; characterization
  tests pin behaviour *before* each extraction, so every step proves nothing broke.

## Naming decision (owner)

`FormService` is too generic — that generic name IS the God-object smell. It stays
only as a **transitional shell** during the extractions; in the final step the
stripped-down CRUD core is renamed to **`FormRepository`** (create/load/update/
delete/list of the .fvform document) and every consumer is repointed in one pass.
No intermediate rename — the name catches up to the shrunken responsibility at the
end, minimizing churn. This matches the sibling names `FormFileLocator` /
`FormLockManager`.

## Shared-helper principle (owner)

If a helper is needed across more than one layer/service, it becomes ONE shared
helper on the service that owns that concern — never duplicated per class. Applied:
- `getUserFolder` → lives on `FormFileLocator` (access concern); `listForms` etc. call
  `$this->fileLocator->getUserFolder()`. No second copy.
- `generateUuid` / `sanitizeFilename` → shared on `FormFactory`. The pre-existing
  duplicate copies (`ResponseService::generateUuid`, `ApiController::sanitizeFilename`)
  are removed and repointed to FormFactory as each of those classes is touched.

## Interface decision (owner's "interfaces only where warranted")

Introduce **exactly one** interface: `IFormVersionCleaner` — it has a genuine second
implementation (`NullVersionCleaner`) and removes an unmockable static from the lock
path. Every other new service is type-hinted as a **concrete class**: PHPUnit mocks
concretes fine, and speculative interfaces would only generate `registerServiceAlias`
boilerplate for a swap that isn't needed. If a second impl ever appears, extract-interface
is a trivial later refactor.

## Target services (`lib/Service/`, except the cleaner in `lib/Versions/`)

| Service | Responsibility | Key methods moved | Constructor deps |
|---|---|---|---|
| `FormFactory` | Pure form construction, no I/O | `createFormStructure`, `applyTemplate`, `generateUuid`, `sanitizeFilename`, `getUniqueFilename` (canonical home) | `IL10N` |
| `FormFileLocator` | Access-resolution core: fileId → readable/writable `File`; owns #90/#101/#136 member lookup + per-request cache | `getFileById`, `getFileByIdPublic`, `getUserFolder`, `resolveFileAsUser`, `cannotAccessException`, `findUsersWithGroupFolderAccess`, `groupFolderGroupsHasCircleId`, `membersOfGroup`, `membersOfCircle`, `findUsersWithStorage` | `IRootFolder`, `IUserSession`, `IDBConnection`, `IGroupManager`, `IServerContainer` |
| `FormLockManager` | Concurrency core (#7/#90/#97/#101): DB-row lock, serialized read-modify-write, short-write verify, version cleanup | `mutateFormFileWithLock`, `reclaimStaleLock`, `releaseLock`, `describeWriteContext`, `deleteVersionsForFile` | `IDBConnection`, `IUserSession`, `IFormVersionCleaner` |
| `FormRepository` | Form-document lifecycle: create/load/update/delete/list | `create`, `createAsUser`, `load`, `loadPublic`, `loadPublicData`, `update`, `delete`, `listForms`, `findFormFileIds`, **`findFormsRecursive`** | `FormFileLocator`, `FormLockManager`, `FormFactory`, `IndexService`, `IDBConnection`, `IMimeTypeLoader`, `IUserSession` |
| `ResponsePersistenceService` | Response-array mutations under the shared lock (#3/#4/#8 TOCTOU) | `appendResponse`, `appendResponsePublic`, `deleteResponse`, `deleteAllResponses`, `savePublic`, `appendResponseWithLock`, `extractFileResponseIds` | `FormFileLocator`, `FormLockManager`, `IndexService`, `UploadService` |
| `UploadService` | Response uploads + hidden sibling folders | `getUploadsFolder`, `getBrandingFolder`, `storeUpload`, `getUpload`, `deleteResponseUploads`, `deleteAllUploads`, `createUploadsZip`, `addFolderToZip`, `sanitizeUploadFilename` | `FormFileLocator`, `FormFactory` |
| `OdtTemplateService` | Per-form ODT export template file | `getTemplatesFolder`, `storeOdtTemplate`, `getOdtTemplate`, `hasOdtTemplate`, `deleteOdtTemplate` | `FormFileLocator` |

`IFormVersionCleaner` (`lib/Versions/`):
- `FilesVersionCleaner` — holds the `class_exists`-guarded lazy `Server::get(IVersionManager)`,
  the no-session owner-fallback, the error-swallowing try/catch (verbatim from the old
  `deleteVersionsForFile`). Deps: `IUserSession`, **`IUserManager`** (the L706 wart lives
  *here*, not on `FormLockManager`).
- `NullVersionCleaner` — no-op.
- Registered in `Application::register()`: bind `IFormVersionCleaner` → `FilesVersionCleaner`
  when `IVersionManager` is available, else `NullVersionCleaner` (one explicit alias).

**DAG (acyclic):** `FormFactory` (leaf) and `FormFileLocator` (leaf) ← everyone.
`FormLockManager` ← Repository, ResponsePersistence. `IFormVersionCleaner` ← FormLockManager.
`UploadService` ← ResponsePersistence. UploadService must never depend back on ResponsePersistence.

## Controller-resident logic that has NO service home (must be placed, not dropped)

`ApiController` mixes thin delegation with real logic. Each item gets an explicit home:

- **`generateShareToken` / `rotateShareToken` (#135)** — token alphabet (CHAR_HUMAN_READABLE),
  entropy, the "no share link to replace" 400-guard, rotate-then-update flow. Called from
  BOTH `update`/`create` AND `rotateShareToken`. → **New `ShareTokenService`** (deps: `ISecureRandom`),
  injected by whichever controller owns form settings. Gets its own characterization test.
- **`searchSharees`** — self-contained sharee search, no FormService call. → stays inline on
  the form controller (named as a mild catch-all, not "mirrors FormRepository").
- **`saveAsTemplate`** — `templateService->addTemplate/getTemplate`. → stays inline, injects `TemplateService`.
- **`rebuildIndex`** — `indexService->rebuildIndex`. → stays inline, injects `IndexService`.
- **`setFavorite`** — getFileById + update. → stays inline on form controller.

## Controller topology (URLs frozen byte-for-byte; only `name=>` prefix changes)

Verified: 0/111 frontend `generateUrl` calls use route *names* (they use URL paths);
0 server-side `linkToRoute('formvox.api.*')`. So renaming the `api#` prefix is frontend-safe.

| Controller | Action | Owns routes (URLs unchanged) | Injects (beyond mirrored service) |
|---|---|---|---|
| `FormController` (new, from ApiController) | forms CRUD + catch-all | list/create/get/update/delete/setFavorite, searchSharees, saveAsTemplate, rebuildIndex, rotateShareToken | `PermissionService`, `IUserSession`, `TemplateService`, `IndexService`, `ShareTokenService` |
| `ResponseController` (new) | response reads/deletes | getResponses/deleteAllResponses/deleteResponse | `PermissionService`, `IUserSession`, `ResponseService` |
| `ExportController` (new) | export capability (spans ResponseService + Odt) | exportCsv/Json/Excel + odt-template upload/download/delete/status | `ResponseService`, `OdtTemplateService`, `PermissionService` |
| `FormUploadController` (new) | upload downloads | downloadUpload/downloadAllUploads | `PermissionService` |
| `PublicController` | (kept — already cohesive) | public/embed/submit/upload | repoint to `FormRepository`+`ResponsePersistenceService`+`UploadService` |
| `BrandingController` | (kept) | branding | **`FormFileLocator`** (its only FS call is `getFileById`), BrandingService uses `UploadService` |
| Integration/External/Import/Page/Presence/Settings/Statistics/Ai/License/FilePermission | (kept) | unchanged URLs | repoint type-hints per consumer map below |

## Consumer repoint map (verified — after FormService is deleted)

| Consumer | Repoints to |
|---|---|
| `ResponseService` | `FormRepository` (load/loadPublic) + `FormFileLocator` (getFileByIdPublic) + `ResponsePersistenceService` (appendResponsePublic) |
| `IntegrationController` | `FormFileLocator` (getFileById) + `FormRepository` (load/update) |
| `PageController` | `FormFileLocator` (getFileById) + `FormRepository` (load) |
| `MSFormsImportService` | `FormRepository` (create/update) + `ResponsePersistenceService` (appendResponse) |
| `BrandingController` | `FormFileLocator` (getFileById) |
| `BrandingService` | `UploadService` (getBrandingFolder) |
| `ExternalApiController` | `FormRepository` (loadPublic) + `ResponsePersistenceService` (savePublic); keeps apiKeyService+webhookService |
| `AiTaskSuccessfulListener` | per its actual FormService calls (verify at repoint time) |

## Test plan — characterization FIRST, on stable seams

Each pins behaviour that survives the move (logic relocates, behaviour doesn't).
All unit-level, mocking `nextcloud/ocp` interfaces, **no running server**.

1. **`routes.php` guard** (two assertions): (a) the SET of `url=>` values is byte-unchanged;
   (b) **every `controller#method` resolves to a real public method** via reflection — this
   is the test that actually catches a botched split (URL-snapshot alone does not).
2. **`FormFactoryTest`** — template merge per template, settings precedence, prefilled
   precedence, filename slugging; `generateUuid` format (not value). Mock `IL10N` (identity `t`).
3. **`FormFileLocatorTest`** — the #90/#101/#136 crown jewel. Mock `IDBConnection` rows for
   `home::`, `__groupfolders/{id}`, `object::groupfolder:{id}`, external; mock
   `IGroupManager::get()->searchUsers()`. Pin: `requireWrite` skips read-only candidates;
   deterministic **sorted + deduped + bounded** member output; **#136** — membership resolves
   through the group *backend* (IGroupManager), not `oc_group_user`; groups-only degradation
   when `groupFolderGroupsHasCircleId()` is false; not-found vs no-writer exception.
4. **`FormLockManagerTest`** — the concurrency core. Mock `File`/`Storage`/`IDBConnection`
   (simulate `OCP\DB\Exception` REASON_UNIQUE_CONSTRAINT_VIOLATION → retry), `IFormVersionCleaner`.
   Pin: mutator runs under lock against fresh content; `modified_at` set; **#97** short-write → throws;
   **#90/#101** refused putContent → wrapped exception with write-context; **#7** stale-lock reclaim;
   lock always released via `finally`.
5. **`savePublicTest`** — pin **full-replace semantics** (responses array replaced verbatim,
   NOT appended) + index rebuilt, all inside one `mutateFormFileWithLock`.
6. **`ShareTokenServiceTest` (#135)** — token alphabet/entropy, "no link to replace" 400-guard,
   rotate-then-update flow.
7. **`UploadService` / `OdtTemplateService`** — folder naming, read-only-throws vs write-creates,
   replace-on-store, zip.

## Extraction order (leaf-first, each a shippable commit; DAG-correct)

The review proved the DAG **requires the lock to exist before its consumers** — so the
lock is extracted EARLY as a **relocate-only** move (6 methods verbatim, no refactor),
not last. "Wholesale relocate" already IS the low-risk move; doing it last would break
the DAG in the interim.

0. Harness (DONE, green). Write tests 1–2 now. ✅
1. **`FormFactory`** — pure leaf. Test 2 green. Repoint `create`/`createAsUser`. ✅ DONE
   - Note: `ApiController::sanitizeFilename` and `ResponseService::generateUuid` are
     independent pre-existing duplicate copies. Consolidate onto FormFactory during
     the later cleanup phase — deliberately NOT in this commit (blast-radius control).
2. **`FormFileLocator`** — leaf. Test 3 green first (crown jewel).
3. **`IFormVersionCleaner` + `FilesVersionCleaner` + `NullVersionCleaner`** — enables step 4.
4. **`FormLockManager`** — relocate-only, behind test 4. Now consumers can inject it.
5. **`UploadService`** + **`OdtTemplateService`** — depend only on locator. Test 7.
6. **`ShareTokenService`** — test 6. Repoint token callers.
7. **`FormRepository`** — depends on locator+lock+factory (all exist now).
8. **`ResponsePersistenceService`** — test 5 (savePublic) green first. Depends on locator+lock+upload.
9. **Controller split** — one controller per commit (FormController, ResponseController,
   ExportController, FormUploadController); move action + its routes atomically; test 1 green each.
10. **Repoint remaining consumers** (map above), then **delete `FormService.php` and `ApiController.php`**.

## Risks + mitigations

- **Write-lock regression (#7/#90/#97/#101)** → relocate-only (verbatim), test 4 green before and after.
- **LDAP member lookup (#136)** → test 3 pins the IGroupManager-backend contract explicitly.
- **Token loss (#135)** → `ShareTokenService` + test 6; called from both create/update and rotate.
- **Botched route split** → test 1's reflection assertion catches unresolved `controller#method`.
- **DI under-provisioning** → each new controller's full dep list is spelled out above (PermissionService/IUserSession/etc.).
- **Branch** → `refactor/formservice-split` off the v1.4.5 tip; nothing pushed without sign-off.
