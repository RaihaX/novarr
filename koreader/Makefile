# Novarr KOReader plugin
#
#   make test      run the unit tests with KOReader's own LuaJIT
#   make package   build dist/novarr-koplugin.zip (root folder: novarr.koplugin/)
#
# KOREADER_DIR must point at a KOReader install's koreader/ folder (the one
# with luajit, common/ and libs/), e.g. an extracted macOS build:
#   make test KOREADER_DIR=/path/to/KOReader.app/Contents/koreader
# LUA overrides the interpreter (default: $(KOREADER_DIR)/luajit).

KOREADER_DIR ?=
LUA ?= $(KOREADER_DIR)/luajit
PLUGIN := novarr.koplugin
DIST := dist
ZIP := $(DIST)/novarr-koplugin.zip
TEST_TMP ?= $(CURDIR)/$(DIST)/test-tmp

.PHONY: test package clean

test:
	@test -n "$(KOREADER_DIR)" || { echo "Set KOREADER_DIR to a KOReader install's koreader/ folder (see the top of this Makefile)"; exit 2; }
	@test -x "$(LUA)" || { echo "No Lua interpreter at $(LUA); set KOREADER_DIR or LUA"; exit 2; }
	@mkdir -p "$(TEST_TMP)"
	KOREADER_DIR="$(KOREADER_DIR)" NOVARR_TEST_TMP="$(TEST_TMP)" "$(LUA)" spec/run.lua
	@rm -rf "$(TEST_TMP)"

package:
	@rm -f "$(ZIP)"
	@mkdir -p "$(DIST)"
	zip -r -X -q "$(ZIP)" "$(PLUGIN)" -x '*.DS_Store' -x '*/._*' -x '__MACOSX/*' -x '*.tmp' -x '*~'
	@unzip -l "$(ZIP)"

clean:
	rm -rf "$(DIST)"
