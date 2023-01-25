import time
import cupy as cp
import numpy as np
import sys
from os import path
from PIL import Image
Image.MAX_IMAGE_PIXELS = 300000*300000

if len(sys.argv) < 8:
    raise ValueError('Should be 7 arguments')

x1 = int(sys.argv[1])
x2 = x1+int(sys.argv[2])
y1 = int(sys.argv[3])
y2 = y1+int(sys.argv[4])
z  = int(sys.argv[5])
ss = int(sys.argv[6]) # square size
nfn = sys.argv[7]

newIm = Image.new('RGB', (ss * abs(x2-x1), ss * abs(y2-y1)))
for x in range(x1, x2, 1):
    print('Reading row {}'.format(abs(x-x1)))
    for y in range(y1, y2, 1):
        fn = 'cache/{}-{}-{}.png'.format(z,y,x)
        im = Image.open(fn)
        newIm.paste(im, (abs((y-y1))*ss, abs((x-x1))*ss))

print('Done reading, now writing')
newIm.save(nfn)
print('Done!')