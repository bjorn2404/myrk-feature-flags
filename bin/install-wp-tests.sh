#!/usr/bin/env bash
# Install WordPress test suite.
# Usage: bash bin/install-wp-tests.sh <db-name> <db-user> <db-pass> [db-host] [wp-version] [skip-db-creation]
# Example: bash bin/install-wp-tests.sh wordpress_test root '' localhost latest

DB_NAME=$1
DB_USER=$2
DB_PASS=$3
DB_HOST=${4-localhost}
WP_VERSION=${5-latest}
SKIP_DB_CREATION=${6-false}

TMPDIR=${TMPDIR-/tmp}
TMPDIR=$(echo "$TMPDIR" | sed -e "s/\/$//")
WP_TESTS_DIR=${WP_TESTS_DIR-$TMPDIR/wordpress-tests-lib}
WP_CORE_DIR=${WP_CORE_DIR-$TMPDIR/wordpress/}

download() {
	if command -v curl &>/dev/null; then
		curl -s "$1" >"$2"
	elif command -v wget &>/dev/null; then
		wget -nv -O "$2" "$1"
	fi
}

if [[ $WP_VERSION =~ ^[0-9]+\.[0-9]+$ ]]; then
	WP_TESTS_TAG="branches/$(printf '%s' "$WP_VERSION")"
elif [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0-9]+ ]]; then
	if [[ $WP_VERSION =~ [0-9]+\.[0-9]+\.[0] ]]; then
		WP_TESTS_TAG="tags/$(printf '%s' "$WP_VERSION" | sed s/[.][0]$/\/)"
	else
		WP_TESTS_TAG="tags/$(printf '%s' "$WP_VERSION")"
	fi
elif [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
	WP_TESTS_TAG='trunk'
else
	# Download current stable version tag.
	download http://api.wordpress.org/core/version-check/1.7/ /tmp/wp-latest.json
	grep -E 'current|version' /tmp/wp-latest.json
	LATEST_VERSION=$(grep -o '"version":"[^"]*"' /tmp/wp-latest.json | sed 's/"version":"//;s/"//')
	if [[ -z "$LATEST_VERSION" ]]; then
		echo "Latest WordPress version could not be determined"
		exit 1
	fi
	WP_TESTS_TAG="tags/$LATEST_VERSION"
fi

set -ex

install_wp() {
	if [ -d "$WP_CORE_DIR" ]; then
		return
	fi
	mkdir -p "$WP_CORE_DIR"

	if [[ $WP_VERSION == 'nightly' || $WP_VERSION == 'trunk' ]]; then
		mkdir -p "$TMPDIR/wordpress-trunk"
		rm -rf "$TMPDIR/wordpress-trunk"
		svn export --quiet https://develop.svn.wordpress.org/trunk/ "$TMPDIR/wordpress-trunk/wordpress"
		mv "$TMPDIR/wordpress-trunk/wordpress" "$WP_CORE_DIR"
	else
		if [ $WP_VERSION == 'latest' ]; then
			local ARCHIVE_NAME='latest'
		else
			local ARCHIVE_NAME="wordpress-$WP_VERSION"
		fi
		download https://wordpress.org/"${ARCHIVE_NAME}".tar.gz "$TMPDIR/wordpress.tar.gz"
		tar --strip-components=1 -zxmf "$TMPDIR/wordpress.tar.gz" -C "$WP_CORE_DIR"
	fi

	download https://raw.github.com/markoheijnen/wp-mysqli/master/db.php "$WP_CORE_DIR/src/wp-content/db.php"
}

install_test_suite() {
	if [ -d "$WP_TESTS_DIR" ]; then
		return
	fi

	if [ false == "$SKIP_DB_CREATION" ]; then
		mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
	fi

	mkdir -p "$WP_TESTS_DIR"

	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/includes/" "$WP_TESTS_DIR/includes"
	svn export --quiet --ignore-externals "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/tests/phpunit/data/" "$WP_TESTS_DIR/data"

	if [ ! -f wp-tests-config.php ]; then
		download "https://develop.svn.wordpress.org/${WP_TESTS_TAG}/wp-tests-config-sample.php" "$WP_TESTS_DIR/wp-tests-config.php"
		# Remove all \r characters.
		tr -d '\r' <"$WP_TESTS_DIR/wp-tests-config.php" >"$TMPDIR/wp-tests-config.php" && mv "$TMPDIR/wp-tests-config.php" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s:dirname( __FILE__ ) . '/src/':'$WP_CORE_DIR':" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/youremptytestdbnamehere/$DB_NAME/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourusernamehere/$DB_USER/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s/yourpasswordhere/$DB_PASS/" "$WP_TESTS_DIR/wp-tests-config.php"
		sed -i "s|localhost|${DB_HOST}|" "$WP_TESTS_DIR/wp-tests-config.php"
	fi
}

install_db() {
	if [ false == "$SKIP_DB_CREATION" ]; then
		mysqladmin create "$DB_NAME" --user="$DB_USER" --password="$DB_PASS" --host="$DB_HOST" || true
	fi
}

install_wp
install_test_suite
install_db
