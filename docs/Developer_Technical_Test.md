### As Development S.A.S.

# Developer Test

### Technical test

**Instructions.** This test assesses your ability to build a complete web application (database, backend and frontend) following current best practices. Build the application in Section 2, then answer the questions about your solution in Section 3; submit as described in Section 4.

You may use AI tools. We value your technical decisions and how you justify them, not just the result. Document any assumptions you make. Estimated time: 4–6 hours.

1. **Candidate details**

    - Email (required)
    - Full name (required)
    - Link to your GitHub or GitLab profile (optional)
    - Years of experience with each technology (fill in the table below):

| **Technology** | **Years of experience** |
| --- | --- |
| PHP |  |
| CodeIgniter (v4) |  |
| MySQL |  |
| JavaScript |  |
| TypeScript |  |
| React |  |
| HTML and CSS |  |
| Git |  |
| REST APIs |  |

2. **Practical test**

**Goal.** Build a user management application with a **React frontend** and a **CodeIgniter backend** that communicate over a REST API. You may use AI tools; we value your decisions and how you justify them, not just the result.

**Database** Create a database on your local server with a USER table and the following fields:

| **Field** | **Rule** |
| --- | --- |
| id | primary key, auto-increment |
| first\_name | required |
| last\_name | required |
| email | required, valid format, unique |
| gender | required |
| telephone | optional |
| age | required |

**Backend — CodeIgniter 4 (REST API)**

- CodeIgniter 4 (4.7.x, PHP 8.1+) installed via Composer; configuration through .env (no credentials in the code).
- Persistence with a Model + Query Builder and migrations (no in-memory arrays).
- Server-side validation: required fields, email format and unique email.
- Expose a JSON REST API for USER and enable CORS so the React app can consume it: GET /users list → 200

GET /users/{id} detail → 200 / 404 POST /users create → 201 / 422

PUT /users/{id} update → 200 / 422 / 404 DELETE /users/{id} delete → 204 / 404

- Seed at least 10 sample users.
- Include at least one meaningful automated test (for example an API/integration test for a USER endpoint).

**Frontend — React**

- A React app (Vite or Next.js) in TypeScript that consumes the CodeIgniter API via fetch or axios.
- List users in a table.
- Create and edit forms with client-side validation.
- Delete with a confirmation step.
- Handle loading and error states, with a clean, reusable component structure.

3. **Technical questions about your solution**

_Answer these after building the application. They refer to the app you just implemented; be concrete about the decisions you actually made._

**Q1. Backend architecture** In this test you are building the CodeIgniter backend that serves the USER data to the React app. Imagine this backend will grow beyond simple CRUD — more entities, business rules, and eventually a scheduled job that reuses the same logic. Describe how you would organize the code: specifically, where request validation lives, where business logic lives, and what you would deliberately keep out of the controllers — and explain why.

**Q2. Data and performance** Imagine the USER list screen has to support searching by name, sorting by different columns, and pagination, and the table grows to around 500,000 rows. First, which database indexes would you add to keep this fast, and how do you decide? Second, if you paginate with LIMIT/OFFSET, explain what happens to performance as the user navigates to deep pages (for example page 5,000) and what you would do about it.

**Q3. API design: concurrent edits and idempotency** Two support agents open the same user record in the React app at the same time. Agent A saves a phone- number change; a few seconds later Agent B saves an age change from the version they loaded before A’s save. (1) What happens with a naïve PUT /users/{id}, and how would you design the API so one agent doesn’t silently overwrite the other’s change? (2) Separately: of your endpoints (GET, POST, PUT, DELETE), which are idempotent, and why does that matter when a client retries a request after a network timeout?

**Q4. React data and state** In the React frontend, the USER list is fetched from the API and the user can create, edit and delete records. Walk through how you would manage that server data: (1) how you cache it and keep the list up to date after a create/edit/delete; (2) how you handle a race where the user triggers two fetches quickly and an older response arrives after the newer one; and (3) how you decide what belongs in local component state, what belongs in a server-data cache, and what (if anything) belongs in global state.

**Q5. Security: authenticating the SPA** The React app and the CodeIgniter API are separate applications talking over HTTP. Describe how you would authenticate a logged-in user across that boundary: (1) what credential or token you would use and where you would store it on the client (and why that location is safe against XSS); (2) how you would protect state-changing requests (POST/PUT/DELETE); and (3) the trade-off between using JWTs and server-side sessions for this app, especially around revoking access. Mention any CodeIgniter 4 features you would rely on.

**Q6. Experience and reflection** Two short parts. (1) Tell us about a real technical decision you made and later reversed — what you originally chose, what evidence or experience changed your mind, and what you took away from it. (2) For the application you are building in this test, describe your testing approach: what you would test and how (for example unit vs integration), and what you would deliberately not spend time testing — and why.

4. **Submission**

    - This document, completed: fill in Section 1 (candidate details) and write your answers under each Section 3 question. Return it as a Word or PDF file.
    - A public Git repository (a monorepo, or two repos for backend and frontend) with a README explaining how to run both parts, and a .env.example. Do not commit credentials or the vendor/ or node\_modules/ folders.

<div></div>

- Optional alternative: if you cannot share a public repository, you may deliver the project as a ZIP file instead — include the same README and .env.example, and exclude the vendor/ and node\_modules/ folders.
- Include the migrations or a SQL dump of the database, and note the API base URL the frontend expects.
- Optional: a live deployment (for example Railway, Render or Vercel).
- No screenshots are required.
