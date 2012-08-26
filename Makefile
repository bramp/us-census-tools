TL=tl_2010
GEOS=county10 cd108 cd111 csa10 sldu10 vtd10 bg10 elsd10 state10 zcta510 cbsa10 scsd10 unsd10

all: $(GEOS)

$(GEOS):
	echo php import_tiger.php $(TL)_us_cbsa10.sqlite $(shell find -name $(TL)_*$@.zip)
