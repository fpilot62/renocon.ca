# Source from a deploy script, or from ops/deploy_to_staging.sh.
# TARGET=live (the default) keeps that script's own docroot and database.
# TARGET=staging points PAYROLL_APP and PAYROLL_DB at the staging copy.
# A caller who already set PAYROLL_APP keeps that path, then staging is
# refused if the path or the database is the live one.

: "${TARGET:=live}"

_live_app=/home/renocon/public_html/payroll
_staging_app=/home/renocon/staging_payroll
_live_db=admin_payroll
_staging_db=admin_payroll_staging

case "$TARGET" in
  live)
    : "${PAYROLL_APP:=$_live_app}"
    : "${PAYROLL_DB:=$_live_db}"
    ;;
  staging)
    : "${PAYROLL_APP:=$_staging_app}"
    : "${PAYROLL_DB:=$_staging_db}"
    : "${PAYROLL_URL:=https://staging.payroll.ecommi.com}"
    : "${PAYROLL_ENV_FILE:=/home/renocon/private/payroll-staging.env}"
    ;;
  *)
    echo "TARGET must be staging or live." >&2
    exit 1
    ;;
esac

if [[ $TARGET == staging ]]; then
  if [[ $PAYROLL_APP == "$_live_app" || $PAYROLL_APP == "$_live_app/" ]]; then
    echo "Refusing TARGET=staging aimed at the live docroot." >&2
    exit 1
  fi
  if [[ $PAYROLL_DB == "$_live_db" ]]; then
    echo "Refusing TARGET=staging aimed at the live database." >&2
    exit 1
  fi
fi

export TARGET PAYROLL_APP PAYROLL_DB
if [[ $TARGET == staging ]]; then
  export PAYROLL_URL PAYROLL_ENV_FILE
fi
unset _live_app _staging_app _live_db _staging_db
