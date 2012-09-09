TL=tl_2010
GEOS=state10 county10 cbsa10 cd111 csa10 sldu10 vtd10 elsd10 scsd10 unsd10 bg10 zcta510

all: $(GEOS)

cd111:
	php import_tiger.php maps/$(TL)_us_$@.sqlite $(shell find -path */111/$(TL)_*$@.zip)

$(filter-out cd111, $(GEOS)):
	php import_tiger.php maps/$(TL)_us_$@.sqlite $(shell find -path */2010/$(TL)_*$@.zip)

.PHONY: all $(GEOS)
