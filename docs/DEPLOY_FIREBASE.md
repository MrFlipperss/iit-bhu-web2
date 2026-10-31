# Deploying on Firebase + Cloud Run

Firebase Hosting serves only static files. It can't run PHP or Python, and it has no MySQL. The working setup is:

```
Firebase Hosting  public/  (the pages)
   └─ rewrite /api/**  →  Cloud Run service "cit-scheduler" (this repo's Dockerfile: Apache + PHP + Python + OR-Tools)
                                └─ MySQL 8: Cloud SQL, or any managed MySQL 8
```

Two Firebase limits are built into the app:
- **60-second request timeout.** Requests forwarded to Cloud Run are cut at 60 seconds, so Generate is budgeted at about 35 seconds.
- **Only the `__session` cookie is forwarded.** The PHP session cookie is named `__session`, and sessions are stored in MySQL, so every Cloud Run instance sees the same logins.

Cloud Run and Cloud SQL need the Firebase **Blaze** (pay-as-you-go) plan. Check the current pricing; Cloud SQL has no free tier.

## 1. One-time setup

```bash
npm install -g firebase-tools        # Firebase CLI
gcloud auth login && firebase login
gcloud config set project YOUR-PROJECT-ID
gcloud services enable run.googleapis.com cloudbuild.googleapis.com sqladmin.googleapis.com secretmanager.googleapis.com
```

## 2. Database (Cloud SQL, MySQL 8)

```bash
gcloud sql instances create cit-mysql --database-version=MYSQL_8_0 --tier=db-f1-micro --region=asia-south1
gcloud sql databases create cit_scheduler --instance=cit-mysql --charset=utf8mb4 --collation=utf8mb4_0900_ai_ci
gcloud sql users create cit --instance=cit-mysql --password='<strong password>'
```

Any other managed MySQL 8 works too. Set `DB_HOST`/`DB_PORT`, plus `DB_SSL_CA` if it requires TLS, instead of `DB_SOCKET`.

## 3. Secrets

```bash
printf '%s' '<db password>'   | gcloud secrets create cit-db-password --data-file=-
printf '%s' '<demo password>' | gcloud secrets create cit-demo-password --data-file=-
printf '%s' '<llm api key>'   | gcloud secrets create cit-llm-key --data-file=-     # optional
```

Give the Cloud Run service account the **Secret Manager Secret Accessor** and **Cloud SQL Client** roles.

## 4. Cloud Run

```bash
gcloud run deploy cit-scheduler --source . --region asia-south1 --allow-unauthenticated \
  --cpu 2 --memory 2Gi --timeout 120 --min-instances 1 --max-instances 3 \
  --add-cloudsql-instances YOUR-PROJECT-ID:asia-south1:cit-mysql \
  --set-env-vars DB_SOCKET=/cloudsql/YOUR-PROJECT-ID:asia-south1:cit-mysql,DB_NAME=cit_scheduler,DB_USER=cit,TEAM_NAME="Your team",REFERENCE_WEEKDAY=MON \
  --set-secrets DB_PASSWORD=cit-db-password:latest,DEMO_PASSWORD=cit-demo-password:latest
# optional AI:  --set-env-vars LLM_PROVIDER=openai,LLM_BASE_URL=...,LLM_MODEL=...,EMBED_MODEL=...  --set-secrets LLM_API_KEY=cit-llm-key:latest
```

- **First start:** the container finds an empty database, builds the schema, imports the starter pack, seeds the demo accounts and indexes the knowledge base (`docker/entrypoint.sh`).
- **`--min-instances 1`** avoids a cold start during judging.
- **`--cpu 2`** gives the solver two cores. More CPU means lower soft penalties within the same time.

**Check:** `curl https://<cloud-run-url>/api/health.php` should return `database: ok` and an `ortools` version.

## 5. Firebase Hosting

```bash
cp .firebaserc.example .firebaserc     # put your project id in it
firebase deploy --only hosting
```

`firebase.json` sends `/api/**` to the service `cit-scheduler` in `asia-south1`. If you used a different service name
or region, change it there. If Hosting reports that the region is not supported for rewrites, deploy Cloud Run to a
region it supports (for example `us-central1`) and update both places.

## 6. Before submitting

1. Log in as `dean@cit.demo` and go to Admin → **Reset demo data**.
2. On the Overview, click **Generate** (and **Improve** if you have time).
3. On Reschedule, click **Load the sample**, then **Propose a repair**, then **Approve and apply**.
4. In Admin → **Exports**, download `schedule.json` and `schedule_after.json` and commit them to `exports/`.
5. Run the two validator commands from the README on the downloaded files.
6. Reset again, so judges start from a clean app.
7. Keep the deployment running until results are announced.
