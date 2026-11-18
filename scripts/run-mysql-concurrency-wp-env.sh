#!/usr/bin/env bash

set -euo pipefail

if ! { [ "${GITHUB_ACTIONS:-}" = 'true' ] && [ "${RUNNER_ENVIRONMENT:-}" = 'github-hosted' ]; } && [ "${PREVIEWSHARE_ALLOW_ISOLATED_DOCKER:-}" != '1' ]; then
	echo 'Run on a GitHub-hosted runner or explicitly opt in on a network-isolated Docker host: wp-env publishes fixture web and database ports.' >&2
	exit 1
fi

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
RUNTIME_DIR="${ROOT_DIR}/tests/Runtime"
WORDPRESS_VERSION="${PREVIEWSHARE_WP_VERSION:?Set PREVIEWSHARE_WP_VERSION to an official WordPress ref.}"
RUN_IDENTIFIER="$(php -r 'echo bin2hex(random_bytes(12));')"
TASK_TMP_ROOT="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
CONFIG_DIR="${TASK_TMP_ROOT%/}/previewshare-mysql-config-${RUN_IDENTIFIER}"
WPENV_TASK_HOME="${TASK_TMP_ROOT%/}/previewshare-mysql-home-${RUN_IDENTIFIER}"
STATE_DIR="${RUNTIME_DIR}/.mysql-concurrency-${RUN_IDENTIFIER}"
STATE_NAME="$(basename "${STATE_DIR}")"

# Do not let inherited Compose selectors or lifecycle scripts redirect wp-env
# to user resources or execute unrelated host commands.
for environment_name in ${!COMPOSE_@} ${!WP_ENV_@}; do
	unset "${environment_name}"
done
WP_ENV_HOME="${WPENV_TASK_HOME}"
export WP_ENV_HOME

if command -v wp-env >/dev/null 2>&1; then
	WP_ENV_COMMAND=( wp-env )
elif [ -x "${ROOT_DIR}/node_modules/.bin/wp-env" ]; then
	WP_ENV_COMMAND=( "${ROOT_DIR}/node_modules/.bin/wp-env" )
else
	echo 'Install the repository npm dependencies to run the MySQL concurrency proof.' >&2
	exit 1
fi

run_wp_env() {
	"${WP_ENV_COMMAND[@]}" "$@"
}

cd "${RUNTIME_DIR}"
environment_started=0
fixture_initialized=0
pid_a=''
pid_b=''
revoke_pid=''
delete_pid=''
wait_for_file() {
	file_path="$1"
	label="$2"
	deadline=$((SECONDS + 30))
	while [ ! -f "${file_path}" ]; do
		if [ "${SECONDS}" -ge "${deadline}" ]; then
			echo "Timed out waiting for ${label}." >&2
			return 1
		fi
		sleep 0.1
	done
}
cleanup() {
	original_status=$?
	trap - EXIT
	cleanup_status=0
	if [ -d "${STATE_DIR}" ] && [ "$(cat "${STATE_DIR}/.previewshare-owned" 2>/dev/null || true)" = "${RUN_IDENTIFIER}" ]; then
		touch "${STATE_DIR}/insert-continue" "${STATE_DIR}/revoke-continue" "${STATE_DIR}/delete-continue"
	fi
	for worker_pid in "${pid_a:-}" "${pid_b:-}" "${revoke_pid:-}" "${delete_pid:-}"; do
		if [ -n "${worker_pid}" ]; then
			wait "${worker_pid}" || true
		fi
	done
	if [ "${fixture_initialized}" -eq 1 ] && [ -f "${STATE_DIR}/config.json" ]; then
		(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-cleanup.php "${STATE_NAME}" >/dev/null) || cleanup_status=$?
	fi
	if [ "${environment_started}" -eq 1 ]; then
		compose_files=( "${WP_ENV_HOME}"/*/docker-compose.yml )
		if [ "${#compose_files[@]}" -eq 1 ] && [ -f "${compose_files[0]}" ] && [[ "${compose_files[0]}" == "${WP_ENV_HOME}/"* ]]; then
			(cd "${CONFIG_DIR}" && run_wp_env stop >/dev/null) || true
			docker compose -f "${compose_files[0]}" down --volumes || cleanup_status=$?
		elif [ "${#compose_files[@]}" -eq 1 ] && [ ! -e "${compose_files[0]}" ]; then
			:
		else
			echo 'Could not identify the unique owned wp-env Compose file for volume teardown.' >&2
			cleanup_status=1
		fi
	fi
	if [ -d "${STATE_DIR}" ]; then
		if [ "$(cat "${STATE_DIR}/.previewshare-owned" 2>/dev/null || true)" = "${RUN_IDENTIFIER}" ]; then
			rm -rf -- "${STATE_DIR}"
		else
			echo 'Refusing to remove concurrency state without the matching ownership marker.' >&2
		fi
	fi
	if [ "${cleanup_status}" -eq 0 ]; then
		if [ -d "${CONFIG_DIR}" ]; then
			if [ "$(cat "${CONFIG_DIR}/.previewshare-owned" 2>/dev/null || true)" = "${RUN_IDENTIFIER}" ]; then
				rm -rf -- "${CONFIG_DIR}"
			else
				echo 'Refusing to remove wp-env config without the matching ownership marker.' >&2
			fi
		fi
		if [ -d "${WP_ENV_HOME}" ]; then
			if [ "$(cat "${WP_ENV_HOME}/.previewshare-owned" 2>/dev/null || true)" = "${RUN_IDENTIFIER}" ]; then
				rm -rf -- "${WP_ENV_HOME}"
			else
				echo 'Refusing to remove wp-env home without the matching ownership marker.' >&2
			fi
		fi
	else
		echo "Retaining task-owned wp-env config and home for recovery: ${CONFIG_DIR} ${WP_ENV_HOME}" >&2
	fi
	if [ "${cleanup_status}" -ne 0 ]; then
		echo 'Disposable MySQL fixture cleanup failed; inspect the isolated wp-env database.' >&2
		exit 1
	fi
	exit "${original_status}"
}
trap cleanup EXIT

for owned_path in "${STATE_DIR}" "${CONFIG_DIR}" "${WP_ENV_HOME}"; do
	if [ -e "${owned_path}" ]; then
		echo "Refusing to reuse an existing task path: ${owned_path}" >&2
		exit 1
	fi
done
mkdir -m 700 "${STATE_DIR}"
printf '%s\n' "${RUN_IDENTIFIER}" > "${STATE_DIR}/.previewshare-owned"
mkdir -m 700 "${CONFIG_DIR}"
printf '%s\n' "${RUN_IDENTIFIER}" > "${CONFIG_DIR}/.previewshare-owned"
mkdir -m 700 "${WP_ENV_HOME}"
printf '%s\n' "${RUN_IDENTIFIER}" > "${WP_ENV_HOME}/.previewshare-owned"
web_ports="$(node -e '
const net=require("net");
const take=()=>new Promise((resolve,reject)=>{const s=net.createServer();s.once("error",reject);s.listen(0,"127.0.0.1",()=>{const p=s.address().port;s.close(e=>e?reject(e):resolve(p));});});
(async()=>{const ports=[];while(ports.length<2){const p=await take();if(!ports.includes(p))ports.push(p);}process.stdout.write(JSON.stringify(ports));})().catch(e=>{console.error(e);process.exit(1);});
')"
node -e 'const fs=require("fs"); const p=process.argv[1]; const root=process.argv[2]; const version=process.argv[3]; const ports=JSON.parse(process.argv[4]); const phpVersion=version==="5.8.17"?"8.0":"8.1"; fs.writeFileSync(`${p}/.wp-env.json`, JSON.stringify({core:`WordPress/WordPress#${version}`,phpVersion,port:ports[0],testsPort:ports[1],plugins:[],mappings:{"wp-content/plugins/previewshare":root},config:{DISABLE_WP_CRON:true}}));' \
	"${CONFIG_DIR}" "${ROOT_DIR}" "${WORDPRESS_VERSION}" "${web_ports}"
node -e 'require("fs").writeFileSync(process.argv[1], JSON.stringify({option_names:[],parent_ids:[],review_ids:[]}));' "${STATE_DIR}/config.json"

environment_started=1
(cd "${CONFIG_DIR}" && run_wp_env start)
(cd "${CONFIG_DIR}" && run_wp_env run cli wp plugin activate previewshare)
fixture_initialized=1
setup_output="$(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-setup.php "${STATE_NAME}")"
printf '%s\n' "${setup_output}"
setup_receipt="$(printf '%s\n' "${setup_output}" | sed -n 's/^PREVIEWSHARE_MYSQL_RUNTIME_RECEIPT=//p')"
node -e 'const r=JSON.parse(process.argv[1]); const expectedPhp=process.argv[2]==="5.8.17"?"8.0.":"8.1."; if(r.wordpress!==process.argv[2] || typeof r.database_version!=="string" || !r.database_version.trim() || typeof r.php_version!=="string" || !r.php_version.startsWith(expectedPhp)) throw new Error("Runtime receipt does not match the requested WordPress, PHP, or database version.");' "${setup_receipt}" "${WORDPRESS_VERSION}"

for round in 1 2 3 4 5; do
	run_id="$(php -r 'echo bin2hex(random_bytes(16));')"
	# The option suffix is unique and canonical hexadecimal, like the production HMAC key.
	option_name="previewshare_review_request_${run_id}$(printf '%032x' "${round}")"
	node -e 'const fs=require("fs"); const p=process.argv[1]; const c=JSON.parse(fs.readFileSync(p,"utf8")); c.option_names.push(process.argv[2]); c.option_name=process.argv[2]; fs.writeFileSync(p,JSON.stringify(c));' \
		"${STATE_DIR}/config.json" "${option_name}"
	rm -f "${STATE_DIR}/insert-paused" "${STATE_DIR}/insert-continue" "${STATE_DIR}/result-a" "${STATE_DIR}/result-b"
	# A inserts the READY reservation and pauses immediately before its claim.
	# B then claims that row in a distinct MySQL session before A resumes.
	(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php a "${STATE_NAME}") &
	pid_a=$!
	wait_for_file "${STATE_DIR}/insert-paused" 'first reservation after insert and before claim'
	(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php b "${STATE_NAME}") &
	pid_b=$!
	wait_for_file "${STATE_DIR}/result-b" 'second reservation claim'
	touch "${STATE_DIR}/insert-continue"
	status_a=0
	status_b=0
	wait "${pid_a}" || status_a=$?
	wait "${pid_b}" || status_b=$?
	if [ "${status_a}" -ne 0 ] || [ "${status_b}" -ne 0 ]; then
		echo "A WordPress worker failed (worker a: ${status_a}, worker b: ${status_b})." >&2
		exit 1
	fi
	pid_a=''
	pid_b=''
	node -e '
const fs=require("fs");
const d=process.argv[1];
const a=JSON.parse(fs.readFileSync(`${d}/result-a`,"utf8"));
const b=JSON.parse(fs.readFileSync(`${d}/result-b`,"utf8"));
if (!(a.inserted===0 && Number.isInteger(b.inserted) && b.inserted>0 && a.connection_id>0 && b.connection_id>0 && a.connection_id!==b.connection_id && b.row_id===b.inserted && b.row_phase==="0")) throw new Error(`Unexpected ordered claim race result: ${JSON.stringify({a,b})}`);
console.log(`Reservation claim race ${process.argv[2]}: session ${b.connection_id} claimed option ${b.row_id}; paused session ${a.connection_id} lost the stale claim.`);
' "${STATE_DIR}" "${round}"
done

# Pause revocation immediately before its compare-and-swap SQL write, after the
# authoritative snapshot has been read. Generate a later link in another
# WordPress process, then release revocation and inspect the persisted state.
rm -f "${STATE_DIR}/revoke-paused" "${STATE_DIR}/revoke-continue" "${STATE_DIR}/result-revoke" "${STATE_DIR}/result-generate"
(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php revoke "${STATE_NAME}") &
revoke_pid=$!
wait_for_file "${STATE_DIR}/revoke-paused" 'revocation snapshot'
(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php generate "${STATE_NAME}")
touch "${STATE_DIR}/revoke-continue"
wait "${revoke_pid}"
revoke_pid=''
node -e 'const fs=require("fs"); const d=process.argv[1]; if(!JSON.parse(fs.readFileSync(`${d}/result-generate`)).stored || !JSON.parse(fs.readFileSync(`${d}/result-revoke`)).revoked) throw new Error("Generation or revocation operation failed.");' "${STATE_DIR}"

# Hold the real retention deletion at WordPress's before_delete_post boundary
# after the service binds its marker to the review ID. A second database session
# scans and cleans orphan markers before the deletion is allowed to continue.
rm -f "${STATE_DIR}/delete-paused" "${STATE_DIR}/delete-continue" "${STATE_DIR}/result-review-delete" "${STATE_DIR}/result-review-cleanup"
(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php review-delete "${STATE_NAME}") &
delete_pid=$!
wait_for_file "${STATE_DIR}/delete-paused" 'review deletion boundary'
(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-worker.php review-cleanup "${STATE_NAME}")
node -e 'const fs=require("fs"); const r=JSON.parse(fs.readFileSync(`${process.argv[1]}/result-review-cleanup`)); const c=JSON.parse(fs.readFileSync(`${process.argv[1]}/config.json`)); if(r.result!==false || !r.review_exists || r.marker_value!==String(c.review.review_id) || r.meta_count!==6) throw new Error(`Cleanup changed the live review or marker: ${JSON.stringify(r)}`); console.log("Review deletion versus orphan cleanup: review, bound marker, and response metadata survived the concurrent orphan scan.");' "${STATE_DIR}"
touch "${STATE_DIR}/delete-continue"
wait "${delete_pid}"
delete_pid=''
(cd "${CONFIG_DIR}" && run_wp_env run cli --env-cwd=/var/www/html/wp-content/plugins/previewshare wp eval-file tests/Runtime/mysql-concurrency-verify.php "${STATE_NAME}")
